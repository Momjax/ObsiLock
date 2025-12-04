<?php

namespace App\Model;

use Medoo\Medoo;

/**
 * Model DownloadLog
 * Journalisation des tentatives de téléchargement via liens publics
 * 
 * @package App\Model
 * @author ObsiLock Team
 * @version 1.0
 */
class DownloadLog
{
    private Medoo $db;

    public function __construct(Medoo $db)
    {
        $this->db = $db;
    }

    /**
     * Créer une entrée de log
     * 
     * @param int $shareId ID du partage
     * @param string $ip Adresse IP du client
     * @param string|null $userAgent User-Agent du navigateur
     * @param bool $success Succès ou échec
     * @param string|null $message Raison de l'échec
     * @param int|null $versionId ID de la version téléchargée (JOUR 4)
     * @return int|false ID du log créé
     */
    public function create(
        int $shareId,
        string $ip,
        ?string $userAgent = null,
        bool $success = true,
        ?string $message = null,
        ?int $versionId = null
    ) {
        $data = [
            'share_id' => $shareId,
            'version_id' => $versionId,
            'ip' => $ip,
            'user_agent' => $userAgent,
            'success' => $success,
            'message' => $message,
            'downloaded_at' => date('Y-m-d H:i:s')
        ];

        $this->db->insert('downloads_log', $data);
        return $this->db->id();
    }

    /**
     * Récupérer les logs d'un partage
     * 
     * @param int $shareId
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function getByShare(int $shareId, int $limit = 50, int $offset = 0): array
    {
        return $this->db->select('downloads_log', '*', [
            'share_id' => $shareId,
            'ORDER' => ['downloaded_at' => 'DESC'],
            'LIMIT' => [$offset, $limit]
        ]);
    }

    /**
     * Compter les logs d'un partage
     * 
     * @param int $shareId
     * @param bool|null $success Filtrer par succès (true), échec (false), ou tous (null)
     * @return int
     */
    public function countByShare(int $shareId, ?bool $success = null): int
    {
        $where = ['share_id' => $shareId];
        
        if ($success !== null) {
            $where['success'] = $success;
        }

        return $this->db->count('downloads_log', $where);
    }

    /**
     * Récupérer le dernier log d'un partage
     * 
     * @param int $shareId
     * @return array|false
     */
    public function getLastByShare(int $shareId)
    {
        return $this->db->get('downloads_log', '*', [
            'share_id' => $shareId,
            'ORDER' => ['downloaded_at' => 'DESC']
        ]);
    }

    /**
     * Récupérer les logs d'un utilisateur (via ses partages)
     * 
     * @param int $userId
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function getByUser(int $userId, int $limit = 50, int $offset = 0): array
    {
        $sql = "SELECT dl.*, s.label as share_label, s.kind, s.target_id
                FROM downloads_log dl
                INNER JOIN shares s ON dl.share_id = s.id
                WHERE s.user_id = :user_id
                ORDER BY dl.downloaded_at DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $this->db->pdo->prepare($sql);
        $stmt->execute([
            'user_id' => $userId,
            'limit' => $limit,
            'offset' => $offset
        ]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Obtenir des statistiques globales par IP
     * (utile pour détecter des abus)
     * 
     * @param string $ip
     * @param int $hours Période en heures
     * @return array
     */
    public function getStatsByIp(string $ip, int $hours = 24): array
    {
        $sql = "SELECT 
                    COUNT(*) as total_attempts,
                    SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as successful_attempts,
                    SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as failed_attempts,
                    MIN(downloaded_at) as first_attempt,
                    MAX(downloaded_at) as last_attempt
                FROM downloads_log
                WHERE ip = :ip
                AND downloaded_at >= DATE_SUB(NOW(), INTERVAL :hours HOUR)";

        $stmt = $this->db->pdo->prepare($sql);
        $stmt->execute([
            'ip' => $ip,
            'hours' => $hours
        ]);

        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Obtenir les téléchargements récents (activité globale)
     * 
     * @param int $limit
     * @return array
     */
    public function getRecent(int $limit = 100): array
    {
        $sql = "SELECT dl.*, s.label as share_label, s.kind, u.email as owner_email
                FROM downloads_log dl
                INNER JOIN shares s ON dl.share_id = s.id
                INNER JOIN users u ON s.user_id = u.id
                ORDER BY dl.downloaded_at DESC
                LIMIT :limit";

        $stmt = $this->db->pdo->prepare($sql);
        $stmt->execute(['limit' => $limit]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Nettoyer les anciens logs (RGPD compliance)
     * 
     * @param int $days Conserver les logs de X derniers jours
     * @return int Nombre de logs supprimés
     */
    public function cleanupOld(int $days = 90): int
    {
        $result = $this->db->delete('downloads_log', [
            'downloaded_at[<]' => date('Y-m-d H:i:s', strtotime("-{$days} days"))
        ]);

        return $result->rowCount();
    }

    /**
     * Anonymiser les logs (remplacer IP par hash)
     * Utile pour conformité RGPD après une certaine période
     * 
     * @param int $days Anonymiser les logs de plus de X jours
     * @return int Nombre de logs anonymisés
     */
    public function anonymizeOld(int $days = 30): int
    {
        $sql = "UPDATE downloads_log
                SET ip = SHA2(CONCAT(ip, :salt), 256),
                    user_agent = NULL
                WHERE downloaded_at < DATE_SUB(NOW(), INTERVAL :days DAY)
                AND LENGTH(ip) < 64"; // Éviter de re-hasher

        $stmt = $this->db->pdo->prepare($sql);
        $stmt->execute([
            'salt' => $_ENV['HMAC_SECRET'] ?? 'salt',
            'days' => $days
        ]);

        return $stmt->rowCount();
    }

    /**
     * Statistiques globales des téléchargements
     * 
     * @return array
     */
    public function getGlobalStats(): array
    {
        $sql = "SELECT 
                    COUNT(*) as total_downloads,
                    SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as successful,
                    SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as failed,
                    COUNT(DISTINCT share_id) as unique_shares,
                    COUNT(DISTINCT ip) as unique_ips,
                    MIN(downloaded_at) as first_download,
                    MAX(downloaded_at) as last_download
                FROM downloads_log";

        $stmt = $this->db->pdo->query($sql);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Détecter les tentatives suspectes (rate limiting)
     * 
     * @param string $ip
     * @param int $minutes Période de vérification
     * @param int $threshold Nombre max de tentatives
     * @return bool TRUE si IP suspecte
     */
    public function isSuspiciousActivity(string $ip, int $minutes = 5, int $threshold = 20): bool
    {
        $sql = "SELECT COUNT(*) as attempts
                FROM downloads_log
                WHERE ip = :ip
                AND downloaded_at >= DATE_SUB(NOW(), INTERVAL :minutes MINUTE)";

        $stmt = $this->db->pdo->prepare($sql);
        $stmt->execute([
            'ip' => $ip,
            'minutes' => $minutes
        ]);

        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return ($result['attempts'] ?? 0) >= $threshold;
    }
}
