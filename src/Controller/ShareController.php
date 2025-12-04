<?php

namespace App\Controller;

use App\Model\Share;
use App\Model\DownloadLog;
use App\Model\FileRepository;
use App\Model\FolderRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * ShareController
 * Gestion des partages sécurisés et des téléchargements publics
 * 
 * @package App\Controller
 * @author ObsiLock Team
 * @version 1.0
 */
class ShareController
{
    private Share $shareModel;
    private DownloadLog $logModel;
    private FileRepository $fileRepo;
    private FolderRepository $folderRepo;

    public function __construct($database)
    {
        $this->shareModel = new Share($database);
        $this->logModel = new DownloadLog($database);
        $this->fileRepo = new FileRepository($database);
        $this->folderRepo = new FolderRepository($database);
    }

    /**
     * POST /shares - Créer un nouveau partage
     */
    public function create(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $user = $request->getAttribute('user');
        $userId = $user['user_id'];

        // Validation des champs requis
        if (!isset($data['kind']) || !isset($data['target_id'])) {
            $response->getBody()->write(json_encode([
                'error' => 'Missing required fields: kind, target_id'
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $kind = $data['kind'];
        $targetId = (int)$data['target_id'];
        $label = $data['label'] ?? null;
        $expiresAt = $data['expires_at'] ?? null;
        $maxUses = isset($data['max_uses']) ? (int)$data['max_uses'] : null;

        // Validation du kind
        if (!in_array($kind, ['file', 'folder'])) {
            $response->getBody()->write(json_encode([
                'error' => 'Invalid kind. Must be "file" or "folder"'
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        // Vérifier que l'utilisateur est propriétaire de la ressource
        if ($kind === 'file') {
            $resource = $this->fileRepo->find($targetId);
            if (!$resource || $resource['user_id'] !== $userId) {
                $response->getBody()->write(json_encode([
                    'error' => 'File not found or access denied'
                ]));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
            }
        } else {
            $resource = $this->folderRepo->find($targetId);
            if (!$resource || $resource['user_id'] !== $userId) {
                $response->getBody()->write(json_encode([
                    'error' => 'Folder not found or access denied'
                ]));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
            }
        }

        // Validation de la date d'expiration
        if ($expiresAt && strtotime($expiresAt) <= time()) {
            $response->getBody()->write(json_encode([
                'error' => 'Expiration date must be in the future'
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        // Validation de max_uses
        if ($maxUses !== null && $maxUses < 1) {
            $response->getBody()->write(json_encode([
                'error' => 'max_uses must be at least 1'
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        // Créer le partage
        $share = $this->shareModel->create($userId, $kind, $targetId, $label, $expiresAt, $maxUses);

        if (!$share) {
            $response->getBody()->write(json_encode([
                'error' => 'Failed to create share'
            ]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }

        // Générer l'URL publique
        $baseUrl = getenv('APP_URL') ?: 'http://api.obsilock.iris.a3n.fr:8080';
        $publicUrl = $baseUrl . '/s/' . $share['token'];

        $response->getBody()->write(json_encode([
            'id' => $share['id'],
            'token' => $share['token'],
            'url' => $publicUrl,
            'kind' => $share['kind'],
            'target_id' => $share['target_id'],
            'label' => $share['label'],
            'expires_at' => $share['expires_at'],
            'max_uses' => $share['max_uses'],
            'remaining_uses' => $share['remaining_uses'],
            'created_at' => $share['created_at']
        ]));

        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }

    /**
     * GET /shares - Lister mes partages
     */
    public function list(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $userId = $user['user_id'];
        $params = $request->getQueryParams();
        
        $limit = isset($params['limit']) ? (int)$params['limit'] : 50;
        $offset = isset($params['offset']) ? (int)$params['offset'] : 0;

        $shares = $this->shareModel->getByUser($userId, $limit, $offset);
        $total = $this->shareModel->countByUser($userId);

        // Enrichir avec les statistiques
        foreach ($shares as &$share) {
            $share['stats'] = $this->shareModel->getStats($share['id']);
            
            // Générer l'URL publique
            $baseUrl = getenv('APP_URL') ?: 'http://api.obsilock.iris.a3n.fr:8080';
            $share['url'] = $baseUrl . '/s/' . $share['token'];
        }

        $response->getBody()->write(json_encode([
            'shares' => $shares,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * POST /shares/{id}/revoke - Révoquer un partage
     */
    public function revoke(Request $request, Response $response, array $args): Response
    {
        $shareId = (int)$args['id'];
        $user = $request->getAttribute('user');
        $userId = $user['user_id'];

        $success = $this->shareModel->revoke($shareId, $userId);

        if (!$success) {
            $response->getBody()->write(json_encode([
                'error' => 'Share not found or access denied'
            ]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        return $response->withStatus(204);
    }

    /**
     * GET /s/{token} - Obtenir les métadonnées d'un partage (PUBLIC)
     */
    public function getPublicMetadata(Request $request, Response $response, array $args): Response
    {
        $token = $args['token'];

        $share = $this->shareModel->getByToken($token);

        if (!$share) {
            $response->getBody()->write(json_encode([
                'error' => 'Share not found'
            ]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        // Vérifier la validité
        $validation = $this->shareModel->isValid($share);
        
        if (!$validation['valid']) {
            $statusCode = 410; // Gone
            $errorMessages = [
                'revoked' => 'This share has been revoked',
                'expired' => 'This share has expired',
                'no_uses_left' => 'This share has no remaining uses'
            ];
            $errorMessage = $errorMessages[$validation['reason']] ?? 'This share is no longer valid';

            $response->getBody()->write(json_encode([
                'error' => $errorMessage,
                'reason' => $validation['reason']
            ]));
            return $response->withStatus($statusCode)->withHeader('Content-Type', 'application/json');
        }

        // Récupérer les métadonnées de la ressource (sans infos sensibles)
        if ($share['kind'] === 'file') {
            $resource = $this->fileRepo->find($share['target_id']);
            $metadata = [
                'name' => $resource['filename'] ?? 'Unknown',
                'size' => $resource['size'] ?? 0,
                'type' => 'file'
            ];
        } else {
            $resource = $this->folderRepo->find($share['target_id']);
            $metadata = [
                'name' => $resource['name'] ?? 'Unknown',
                'type' => 'folder'
            ];
        }

        $response->getBody()->write(json_encode([
            'token' => $token,
            'label' => $share['label'],
            'kind' => $share['kind'],
            'metadata' => $metadata,
            'expires_at' => $share['expires_at'],
            'remaining_uses' => $share['remaining_uses'],
            'created_at' => $share['created_at']
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * POST /s/{token}/download - Télécharger via partage public (PUBLIC)
     */
    public function downloadPublic(Request $request, Response $response, array $args): Response
    {
        $token = $args['token'];
        
        // Récupérer IP et User-Agent
        $ip = $this->getClientIp($request);
        $userAgent = $request->getHeaderLine('User-Agent');

        $share = $this->shareModel->getByToken($token);

        if (!$share) {
            // Log échec
            $this->logModel->create(0, $ip, $userAgent, false, 'Share not found');
            
            $response->getBody()->write(json_encode([
                'error' => 'Share not found'
            ]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        // Vérifier la validité
        $validation = $this->shareModel->isValid($share);
        
        if (!$validation['valid']) {
            // Log échec avec raison
            $this->logModel->create($share['id'], $ip, $userAgent, false, $validation['reason']);
            
            $statusCode = 410;
            $errorMessages = [
                'revoked' => 'This share has been revoked',
                'expired' => 'This share has expired',
                'no_uses_left' => 'This share has no remaining uses'
            ];
            $errorMessage = $errorMessages[$validation['reason']] ?? 'This share is no longer valid';

            $response->getBody()->write(json_encode([
                'error' => $errorMessage
            ]));
            return $response->withStatus($statusCode)->withHeader('Content-Type', 'application/json');
        }

        // Décrémenter le compteur (si max_uses défini)
        if ($share['max_uses'] !== null) {
            $decremented = $this->shareModel->decrementUses($share['id']);
            if (!$decremented) {
                // Condition de course: plus d'utilisations
                $this->logModel->create($share['id'], $ip, $userAgent, false, 'No uses left (race condition)');
                
                $response->getBody()->write(json_encode([
                    'error' => 'This share has no remaining uses'
                ]));
                return $response->withStatus(410)->withHeader('Content-Type', 'application/json');
            }
        }

        // Télécharger le fichier
        if ($share['kind'] === 'file') {
            $file = $this->fileRepo->find($share['target_id']);
            
            if (!$file) {
                $this->logModel->create($share['id'], $ip, $userAgent, false, 'File not found');
                
                $response->getBody()->write(json_encode([
                    'error' => 'File not found'
                ]));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
            }

            $filePath = __DIR__ . '/../../storage/uploads/' . $file['stored_name'];

            if (!file_exists($filePath)) {
                $this->logModel->create($share['id'], $ip, $userAgent, false, 'File not found on disk');
                
                $response->getBody()->write(json_encode([
                    'error' => 'File not found on server'
                ]));
                return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
            }

            // Log succès
            $this->logModel->create($share['id'], $ip, $userAgent, true, null);

            // Stream le fichier
            $response = $response
                ->withHeader('Content-Type', 'application/octet-stream')
                ->withHeader('Content-Disposition', 'attachment; filename="' . $file['filename'] . '"')
                ->withHeader('Content-Length', filesize($filePath));

            $response->getBody()->write(file_get_contents($filePath));
            return $response;

        } else {
            // TODO: Téléchargement de dossier (zip)
            $this->logModel->create($share['id'], $ip, $userAgent, false, 'Folder download not implemented');
            
            $response->getBody()->write(json_encode([
                'error' => 'Folder download not yet implemented'
            ]));
            return $response->withStatus(501)->withHeader('Content-Type', 'application/json');
        }
    }

    /**
     * Obtenir l'IP réelle du client (gérer les proxies)
     */
    private function getClientIp(Request $request): string
    {
        $serverParams = $request->getServerParams();
        
        // Vérifier les headers de proxy
        if (!empty($serverParams['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $serverParams['HTTP_X_FORWARDED_FOR']);
            return trim($ips[0]);
        }
        
        if (!empty($serverParams['HTTP_X_REAL_IP'])) {
            return $serverParams['HTTP_X_REAL_IP'];
        }
        
        return $serverParams['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}