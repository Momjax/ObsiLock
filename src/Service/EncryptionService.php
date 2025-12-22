<?php

namespace App\Service;

/**
 * Service de chiffrement AES-256-GCM avec libsodium
 * 
 * Chiffre et déchiffre les fichiers uploadés pour garantir
 * la confidentialité au repos.
 * 
 * @package App\Service
 */
class EncryptionService
{
    private string $masterKey;

    /**
     * @param string $masterKey Clé maître en base64 (32 octets)
     */
    public function __construct(string $masterKey)
    {
        // Décoder la clé maître depuis base64
        $this->masterKey = base64_decode($masterKey);
        
        if (strlen($this->masterKey) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('Master key must be 32 bytes');
        }
    }

    /**
     * Chiffre un fichier en streaming avec libsodium
     * 
     * @param string $inputPath Chemin du fichier source
     * @param string $outputPath Chemin du fichier chiffré
     * @return array ['key' => string, 'nonce' => string] en base64
     * @throws \RuntimeException
     */
    public function encryptFile(string $inputPath, string $outputPath): array
    {
        if (!file_exists($inputPath)) {
            throw new \RuntimeException('Input file does not exist');
        }

        // Générer une clé de contenu aléatoire (32 octets)
        $contentKey = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        
        // Générer un nonce aléatoire (24 octets pour secretbox)
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        // Ouvrir les fichiers
        $inputHandle = fopen($inputPath, 'rb');
        $outputHandle = fopen($outputPath, 'wb');

        if (!$inputHandle || !$outputHandle) {
            throw new \RuntimeException('Cannot open files for encryption');
        }

        // Chiffrer par blocs de 8KB
        $chunkSize = 8192;
        
        while (!feof($inputHandle)) {
            $chunk = fread($inputHandle, $chunkSize);
            
            if ($chunk === false) {
                break;
            }
            
            // Chiffrer le chunk avec libsodium secretbox
            $encrypted = sodium_crypto_secretbox($chunk, $nonce, $contentKey);
            
            // Écrire le chunk chiffré
            fwrite($outputHandle, $encrypted);
            
            // Incrémenter le nonce pour chaque chunk (important!)
            sodium_increment($nonce);
        }

        fclose($inputHandle);
        fclose($outputHandle);

        // Chiffrer la clé de contenu avec la clé maître
        $resetNonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $encryptedKey = sodium_crypto_secretbox($contentKey, $resetNonce, $this->masterKey);

        // Nettoyer la mémoire
        sodium_memzero($contentKey);

        return [
            'key_envelope' => base64_encode($encryptedKey),
            'nonce' => base64_encode($resetNonce),
            'chunk_nonce_start' => base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES))
        ];
    }

    /**
     * Déchiffre un fichier
     * 
     * @param string $inputPath Chemin du fichier chiffré
     * @param string $outputPath Chemin du fichier déchiffré
     * @param string $keyEnvelope Clé chiffrée (base64)
     * @param string $nonce Nonce utilisé (base64)
     * @throws \RuntimeException
     */
    public function decryptFile(
        string $inputPath, 
        string $outputPath, 
        string $keyEnvelope, 
        string $nonce
    ): void {
        if (!file_exists($inputPath)) {
            throw new \RuntimeException('Encrypted file does not exist');
        }

        // Déchiffrer la clé de contenu
        $encryptedKey = base64_decode($keyEnvelope);
        $decodedNonce = base64_decode($nonce);
        
        $contentKey = sodium_crypto_secretbox_open($encryptedKey, $decodedNonce, $this->masterKey);
        
        if ($contentKey === false) {
            throw new \RuntimeException('Cannot decrypt content key');
        }

        // Ouvrir les fichiers
        $inputHandle = fopen($inputPath, 'rb');
        $outputHandle = fopen($outputPath, 'wb');

        if (!$inputHandle || !$outputHandle) {
            sodium_memzero($contentKey);
            throw new \RuntimeException('Cannot open files for decryption');
        }

        // Recréer le nonce initial des chunks
        $chunkNonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        
        // Taille d'un chunk chiffré (8KB + overhead libsodium)
        $encryptedChunkSize = 8192 + SODIUM_CRYPTO_SECRETBOX_MACBYTES;

        while (!feof($inputHandle)) {
            $encryptedChunk = fread($inputHandle, $encryptedChunkSize);
            
            if ($encryptedChunk === false || strlen($encryptedChunk) === 0) {
                break;
            }

            // Déchiffrer le chunk
            $decrypted = sodium_crypto_secretbox_open($encryptedChunk, $chunkNonce, $contentKey);
            
            if ($decrypted === false) {
                fclose($inputHandle);
                fclose($outputHandle);
                sodium_memzero($contentKey);
                throw new \RuntimeException('Decryption failed');
            }

            fwrite($outputHandle, $decrypted);
            
            // Incrémenter le nonce
            sodium_increment($chunkNonce);
        }

        fclose($inputHandle);
        fclose($outputHandle);
        
        // Nettoyer la mémoire
        sodium_memzero($contentKey);
    }

    /**
     * Chiffre simplement des données en mémoire (pour petits fichiers)
     * 
     * @param string $data Données à chiffrer
     * @return array ['encrypted' => string, 'key' => string, 'nonce' => string]
     */
    public function encryptData(string $data): array
    {
        $contentKey = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        
        $encrypted = sodium_crypto_secretbox($data, $nonce, $contentKey);
        
        // Chiffrer la clé avec la clé maître
        $keyNonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $encryptedKey = sodium_crypto_secretbox($contentKey, $keyNonce, $this->masterKey);
        
        sodium_memzero($contentKey);

        return [
            'encrypted' => base64_encode($encrypted),
            'key_envelope' => base64_encode($encryptedKey),
            'nonce' => base64_encode($nonce),
            'key_nonce' => base64_encode($keyNonce)
        ];
    }

    /**
     * Déchiffre des données simples
     * 
     * @param string $encrypted Données chiffrées (base64)
     * @param string $keyEnvelope Clé chiffrée (base64)
     * @param string $nonce Nonce (base64)
     * @param string $keyNonce Nonce de la clé (base64)
     * @return string Données déchiffrées
     */
    public function decryptData(
        string $encrypted, 
        string $keyEnvelope, 
        string $nonce,
        string $keyNonce
    ): string {
        // Déchiffrer la clé de contenu
        $encryptedKey = base64_decode($keyEnvelope);
        $decodedKeyNonce = base64_decode($keyNonce);
        
        $contentKey = sodium_crypto_secretbox_open($encryptedKey, $decodedKeyNonce, $this->masterKey);
        
        if ($contentKey === false) {
            throw new \RuntimeException('Cannot decrypt content key');
        }

        // Déchiffrer les données
        $encryptedData = base64_decode($encrypted);
        $decodedNonce = base64_decode($nonce);
        
        $decrypted = sodium_crypto_secretbox_open($encryptedData, $decodedNonce, $contentKey);
        
        sodium_memzero($contentKey);
        
        if ($decrypted === false) {
            throw new \RuntimeException('Decryption failed');
        }

        return $decrypted;
    }

    /**
     * Génère une clé maître sécurisée (à faire UNE SEULE FOIS)
     * 
     * @return string Clé en base64
     */
    public static function generateMasterKey(): string
    {
        $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        return base64_encode($key);
    }
}
