<?php

namespace App\Model;

use Medoo\Medoo;

/**
 * Model Share
 * Gestion des partages sécurisés de fichiers et dossiers
 * 
 * @package App\Model
 * @author ObsiLock Team
 * @version 1.0
 */
class Share
{
    private Medoo $db;

    public function __construct(Medoo $db)
    {
        $this->db = $db;
    }

    /**
     * Créer un nouveau partage
     * 
     * @param int $userId ID de l'utilisateur créateur
     * @param string $kind Type de ressource ('file' ou 'folder')
     * @param int $targetId ID de la ressource
     * @param string|null $label Label personnalisé
     * @param string|null $expiresAt Date d'expiration (format: Y-m-d H:i:s)
     * @param int|null $maxUses Nombre maximum d'utilisations
     * @return array|false Données du partage créé ou false
     */
    public function create(
        int $userId,
        string $kind,
        int $targetId,
        ?string $label = null,
        ?string $expiresAt = null,
        ?int $maxUses = null
    ) {
        // Générer le token sécurisé
        $tokenData = $this->generateSecureToken($userId, $kind, $targetId);
        
        $data = [
            'user_id' => $userId,
            'kind' => $kind,
            'target_id' => $targetId,
            'label' => $label,
            'token' => $tokenData['token'],
            'token_signature' => $tokenData['signature'],
            'expires_at' => $expiresAt,
            'max_uses' => $maxUses,
            'remaining_uses' => $maxUses, // Initialement égal à max_uses
            'is_revoked' => false
        ];

        $this->db->insert('shares', $data);
        $shareId = $this->db->id();

        if ($shareId) {
            return $this->getById($shareId);
        }

        return false;
    }

    /**
     * Récupérer un partage par son ID
     * 
     * @param int $id
     * @return array|false
     */
    public function getById(int $id)
    {
        return $this->db->get('shares', '*', ['id' => $id]);
    }

    /**
     * Récupérer un partage par son token
     * 
     * @param string $token
     * @return array|false
     */
    public function getByToken(string $token)
    {
        return $this->db->get('shares', '*', ['token' => $token]);
    }

    /**
     * Lister les partages d'un utilisateur
     * 
     * @param int $userId
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function getByUser(int $userId, int $limit = 50, int $offset = 0): array
    {
        return $this->db->select('shares', '*', [
            'user_id' => $userId,
            'ORDER' => ['created_at' => 'DESC'],
            'LIMIT' => [$offset, $limit]
        ]);
    }

    /**
     * Compter les partages d'un utilisateur
     * 
     * @param int $userId
     * @return int
     */
    public function countByUser(int $userId): int
    {
        return $this->db->count('shares', ['user_id' => $userId]);
    }

    /**
     * Révoquer un partage
     * 
     * @param int $id
     * @param int $userId ID de l'utilisateur (pour vérifier ownership)
     * @return bool
     */
    public function revoke(int $id, int $userId): bool
    {
        $result = $this->db->update('shares', 
            ['is_revoked' => true],
            [
                'id' => $id,
                'user_id' => $userId
            ]
        );

        return $result->rowCount() > 0;
    }

    /**
     * Vérifier si un partage est valide
     * 
     * @param array $share Données du partage
     * @return array ['valid' => bool, 'reason' => string|null]
     */
    public function isValid(array $share): array
    {
        // Partage révoqué
        if ($share['is_revoked']) {
            return ['valid' => false, 'reason' => 'revoked'];
        }

        // Partage expiré
        if ($share['expires_at'] && strtotime($share['expires_at']) < time()) {
            return ['valid' => false, 'reason' => 'expired'];
        }

        // Plus d'utilisations disponibles
        if ($share['max_uses'] !== null && $share['remaining_uses'] <= 0) {
            return ['valid' => false, 'reason' => 'no_uses_left'];
        }

        return ['valid' => true, 'reason' => null];
    }

    /**
     * Décrémenter le compteur d'utilisations (atomique)
     * 
     * @param int $shareId
     * @return bool TRUE si succès, FALSE si plus d'utilisations
     */
    public function decrementUses(int $shareId): bool
    {
        // UPDATE atomique avec condition
        $sql = "UPDATE shares 
                SET remaining_uses = remaining_uses - 1 
                WHERE id = :id 
                AND (remaining_uses > 0 OR remaining_uses IS NULL)
                AND max_uses IS NOT NULL";

        $stmt = $this->db->pdo->prepare($sql);
        $stmt->execute(['id' => $shareId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Générer un token sécurisé avec signature HMAC
     * 
     * @param int $userId
     * @param string $kind
     * @param int $targetId
     * @return array ['token' => string, 'signature' => string]
     */
    private function generateSecureToken(int $userId, string $kind, int $targetId): array
    {
        // Secret key pour HMAC (devrait être dans .env en production)
        $secretKey = $_ENV['HMAC_SECRET'] ?? 'default_secret_key_change_in_production';

        // Générer 32 octets de random
        $randomBytes = random_bytes(32);
        $token = rtrim(strtr(base64_encode($randomBytes), '+/', '-_'), '=');

        // Payload pour signature
        $payload = implode('|', [$token, $userId, $kind, $targetId, time()]);

        // Signature HMAC SHA-256
        $signature = hash_hmac('sha256', $payload, $secretKey);

        return [
            'token' => $token,
            'signature' => $signature
        ];
    }

    /**
     * Vérifier la signature d'un token
     * 
     * @param string $token
     * @param string $signature
     * @param array $share Données du partage
     * @return bool
     */
    public function verifyTokenSignature(string $token, string $signature, array $share): bool
    {
        $secretKey = $_ENV['HMAC_SECRET'] ?? 'default_secret_key_change_in_production';

        // Recalculer la signature avec les données du partage
        $payload = implode('|', [
            $token,
            $share['user_id'],
            $share['kind'],
            $share['target_id'],
            strtotime($share['created_at'])
        ]);

        $expectedSignature = hash_hmac('sha256', $payload, $secretKey);

        // Comparaison timing-safe
        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Obtenir les statistiques d'un partage
     * 
     * @param int $shareId
     * @return array
     */
    public function getStats(int $shareId): array
    {
        $share = $this->getById($shareId);
        
        if (!$share) {
            return [];
        }

        // Compter les téléchargements via DownloadLog
        $downloadLog = new DownloadLog($this->db);
        $downloads = $downloadLog->countByShare($shareId);
        $successfulDownloads = $downloadLog->countByShare($shareId, true);
        $failedDownloads = $downloadLog->countByShare($shareId, false);
        $lastDownload = $downloadLog->getLastByShare($shareId);

        return [
            'share_id' => $shareId,
            'total_downloads' => $downloads,
            'successful_downloads' => $successfulDownloads,
            'failed_downloads' => $failedDownloads,
            'remaining_uses' => $share['remaining_uses'],
            'last_download_at' => $lastDownload ? $lastDownload['downloaded_at'] : null,
            'is_expired' => $share['expires_at'] && strtotime($share['expires_at']) < time(),
            'is_revoked' => (bool)$share['is_revoked']
        ];
    }

    /**
     * Supprimer les partages expirés (cleanup)
     * 
     * @return int Nombre de partages supprimés
     */
    public function cleanupExpired(): int
    {
        $result = $this->db->delete('shares', [
            'expires_at[<]' => date('Y-m-d H:i:s'),
            'is_revoked' => true
        ]);

        return $result->rowCount();
    }
}
