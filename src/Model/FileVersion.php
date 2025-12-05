<?php

namespace App\Model;

use Medoo\Medoo;

/**
 * Model FileVersion
 * Gestion des versions multiples de fichiers
 * 
 * @package App\Model
 * @author ObsiLock Team
 * @version 1.0
 */
class FileVersion
{
    private Medoo $db;

    public function __construct(Medoo $db)
    {
        $this->db = $db;
    }

    /**
     * Créer une nouvelle version
     * 
     * @param int $fileId ID du fichier parent
     * @param array $data Données de la version
     * @return int|false ID de la version créée
     */
    public function create(int $fileId, array $data)
    {
        // Récupérer le dernier numéro de version
        $lastVersion = $this->getLastVersionNumber($fileId);
        $newVersion = $lastVersion + 1;

        $versionData = [
            'file_id' => $fileId,
            'version' => $newVersion,
            'stored_name' => $data['stored_name'],
            'size' => $data['size'],
            'checksum' => $data['checksum'],
            'mime_type' => $data['mime_type'] ?? null,
            'iv' => $data['iv'] ?? null,
            'auth_tag' => $data['auth_tag'] ?? null,
            'key_envelope' => $data['key_envelope'] ?? null
        ];

        $this->db->insert('file_versions', $versionData);
        $versionId = $this->db->id();

        if ($versionId) {
            // Mettre à jour current_version dans files
            $this->db->update('files', 
                ['current_version' => $newVersion],
                ['id' => $fileId]
            );
        }

        return $versionId;
    }

    /**
     * Récupérer le dernier numéro de version
     * 
     * @param int $fileId
     * @return int
     */
    public function getLastVersionNumber(int $fileId): int
{
    // Utiliser PDO directement
    $pdo = $this->db->pdo;
    $stmt = $pdo->prepare("SELECT MAX(version) as max_version FROM file_versions WHERE file_id = :file_id");
    $stmt->execute(['file_id' => $fileId]);
    $result = $stmt->fetch(\PDO::FETCH_ASSOC);

    return $result && $result['max_version'] ? (int)$result['max_version'] : 0;
}

    /**
     * Récupérer une version spécifique
     * 
     * @param int $fileId
     * @param int $version
     * @return array|false
     */
    public function getByVersion(int $fileId, int $version)
    {
        return $this->db->get('file_versions', '*', [
            'file_id' => $fileId,
            'version' => $version
        ]);
    }

    /**
     * Récupérer la dernière version
     * 
     * @param int $fileId
     * @return array|false
     */
    public function getLatest(int $fileId)
    {
        return $this->db->get('file_versions', '*', [
            'file_id' => $fileId,
            'ORDER' => ['version' => 'DESC']
        ]);
    }

    /**
     * Lister toutes les versions d'un fichier
     * 
     * @param int $fileId
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function listByFile(int $fileId, int $limit = 50, int $offset = 0): array
    {
        return $this->db->select('file_versions', '*', [
            'file_id' => $fileId,
            'ORDER' => ['version' => 'DESC'],
            'LIMIT' => [$offset, $limit]
        ]);
    }

    /**
     * Compter les versions d'un fichier
     * 
     * @param int $fileId
     * @return int
     */
    public function countByFile(int $fileId): int
    {
        return $this->db->count('file_versions', ['file_id' => $fileId]);
    }

    /**
     * Récupérer une version par son ID
     * 
     * @param int $versionId
     * @return array|false
     */
    public function getById(int $versionId)
    {
        return $this->db->get('file_versions', '*', ['id' => $versionId]);
    }

    /**
     * Supprimer une version spécifique
     * (À utiliser avec précaution - les versions sont normalement immutables)
     * 
     * @param int $versionId
     * @return bool
     */
    public function delete(int $versionId): bool
    {
        $result = $this->db->delete('file_versions', ['id' => $versionId]);
        return $result->rowCount() > 0;
    }

    /**
     * Calculer la taille totale de toutes les versions d'un fichier
     * 
     * @param int $fileId
     * @return int
     */
    public function getTotalSize(int $fileId): int
    {
        return (int)$this->db->sum('file_versions', 'size', ['file_id' => $fileId]) ?: 0;
    }

    /**
     * Obtenir les statistiques des versions
     * 
     * @param int $fileId
     * @return array
     */
    public function getStats(int $fileId): array
    {
        $versions = $this->listByFile($fileId);
        $count = $this->countByFile($fileId);
        $totalSize = $this->getTotalSize($fileId);
        $latest = $this->getLatest($fileId);

        return [
            'total_versions' => $count,
            'total_size' => $totalSize,
            'current_version' => $latest ? $latest['version'] : 0,
            'latest_created_at' => $latest ? $latest['created_at'] : null,
            'versions' => array_map(function($v) {
                return [
                    'version' => $v['version'],
                    'size' => $v['size'],
                    'checksum' => substr($v['checksum'], 0, 16) . '...',
                    'created_at' => $v['created_at']
                ];
            }, array_slice($versions, 0, 5)) // Les 5 dernières versions
        ];
    }
}