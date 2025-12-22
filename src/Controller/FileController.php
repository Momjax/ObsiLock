<?php
namespace App\Controller;

use App\Model\FileRepository;
use App\Model\UserRepository;
use App\Model\FileVersion;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class FileController
{
    private FileRepository $files;
    private UserRepository $users;
    private FileVersion $versions;
    private string $uploadDir;

    public function __construct(FileRepository $files, UserRepository $users, string $uploadDir, $database = null)
    {
        $this->files = $files;
        $this->users = $users;
        $this->uploadDir = $uploadDir;
        
        // Initialiser FileVersion si database est fourni
        if ($database) {
            $this->versions = new FileVersion($database);
        }
    }

    // GET /files
    public function list(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $data = $this->files->listByUser($user['user_id']);

        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // POST /files (upload)
    public function upload(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $uploadedFiles = $request->getUploadedFiles();
        $params = $request->getParsedBody(); // ← AJOUTÉ : Récupérer les paramètres POST

        if (!isset($uploadedFiles['file'])) {
            $response->getBody()->write(json_encode(['error' => 'Aucun fichier']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $file = $uploadedFiles['file'];

        if ($file->getError() !== UPLOAD_ERR_OK) {
            $response->getBody()->write(json_encode(['error' => 'Erreur upload']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $size = $file->getSize();
        $userInfo = $this->users->find($user['user_id']);

        // Vérifier quota
        if (($userInfo['quota_used'] + $size) > $userInfo['quota_total']) {
            $response->getBody()->write(json_encode(['error' => 'Quota dépassé']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(413);
        }

        $originalName = $file->getClientFilename();
        $mimeType = $file->getClientMediaType();
        $storedName = uniqid('f_', true) . '_' . $originalName;

        // Sauvegarder le fichier
        $targetPath = $this->uploadDir . DIRECTORY_SEPARATOR . $storedName;
        $file->moveTo($targetPath);

        // Calculer checksum
        $checksum = hash_file('sha256', $targetPath);

        // ← AJOUTÉ : Récupérer folder_id depuis les paramètres (peut être null)
        $folderId = isset($params['folder_id']) && $params['folder_id'] !== '' 
            ? (int)$params['folder_id'] 
            : null;

        // Créer l'entrée en BDD
        $fileId = $this->files->create([
            'user_id' => $user['user_id'],
            'folder_id' => $folderId, // ← CORRIGÉ : Utilise le folder_id récupéré
            'filename' => $originalName,
            'stored_name' => $storedName,
            'size' => $size,
            'mime_type' => $mimeType,
            'checksum' => $checksum,
            // current_version sera automatiquement = 1 (DEFAULT)
        ]);

        // Créer la version 1 si le système de versioning est activé
        if (isset($this->versions)) {
            $this->versions->create($fileId, [
                'stored_name' => $storedName,
                'size' => $size,
                'checksum' => $checksum,
                'mime_type' => $mimeType
            ]);
        }

        // Mettre à jour le quota
        $this->users->updateQuota($user['user_id'], $userInfo['quota_used'] + $size);

        $response->getBody()->write(json_encode([
            'message' => 'Fichier uploadé',
            'id' => $fileId,
            'version' => 1,
            'folder_id' => $folderId // ← AJOUTÉ : Confirmer le folder_id
        ]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
    }

    // GET /files/{id}
    public function show(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $fileId = (int)$args['id'];
        $file = $this->files->find($fileId);

        if (!$file || $file['user_id'] !== $user['user_id']) {
            $response->getBody()->write(json_encode(['error' => 'Fichier introuvable']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        // Enrichir avec les informations de versions si disponible
        if (isset($this->versions)) {
            $versionsCount = $this->versions->countByFile($fileId);
            $stats = $this->versions->getStats($fileId);
            
            $file['versions_info'] = [
                'current_version' => $file['current_version'] ?? 1,
                'total_versions' => $versionsCount,
                'stats' => $stats
            ];
        }

        $response->getBody()->write(json_encode($file));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // GET /files/{id}/download
    public function download(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $fileId = (int)$args['id'];
        $file = $this->files->find($fileId);

        if (!$file || $file['user_id'] !== $user['user_id']) {
            $response->getBody()->write('Fichier introuvable');
            return $response->withStatus(404);
        }

        $path = $this->uploadDir . DIRECTORY_SEPARATOR . $file['stored_name'];

        if (!file_exists($path)) {
            $response->getBody()->write('Fichier manquant');
            return $response->withStatus(500);
        }

        $stream = fopen($path, 'rb');
        $response->getBody()->write(stream_get_contents($stream));
        fclose($stream);

        return $response
            ->withHeader('Content-Type', $file['mime_type'])
            ->withHeader('Content-Disposition', 'attachment; filename="' . $file['filename'] . '"');
    }

    // DELETE /files/{id}
    public function delete(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $fileId = (int)$args['id'];
        $file = $this->files->find($fileId);

        if (!$file || $file['user_id'] !== $user['user_id']) {
            $response->getBody()->write(json_encode(['error' => 'Fichier introuvable']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        // Supprimer le fichier physique
        $path = $this->uploadDir . DIRECTORY_SEPARATOR . $file['stored_name'];
        if (file_exists($path)) {
            unlink($path);
        }

        // Supprimer toutes les versions physiques si le versioning est activé
        if (isset($this->versions)) {
            $versions = $this->versions->listByFile($fileId);
            foreach ($versions as $version) {
                $versionPath = $this->uploadDir . DIRECTORY_SEPARATOR . $version['stored_name'];
                if (file_exists($versionPath)) {
                    unlink($versionPath);
                }
            }
        }

        // Supprimer de la BDD (cascade supprimera les versions)
        $this->files->delete($fileId);

        // Mettre à jour le quota
        $userInfo = $this->users->find($user['user_id']);
        $this->users->updateQuota($user['user_id'], $userInfo['quota_used'] - $file['size']);

        $response->getBody()->write(json_encode(['message' => 'Fichier supprimé']));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // GET /stats
    public function stats(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $userInfo = $this->users->find($user['user_id']);

        $response->getBody()->write(json_encode([
            'quota_total' => $userInfo['quota_total'],
            'quota_used' => $userInfo['quota_used'],
            'quota_remaining' => $userInfo['quota_total'] - $userInfo['quota_used']
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // GET /me/quota - Stats quota
    public function quota(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $userInfo = $this->users->find($user['user_id']);

        $percent = $userInfo['quota_total'] > 0 
            ? round(($userInfo['quota_used'] / $userInfo['quota_total']) * 100, 2) 
            : 0;

        $response->getBody()->write(json_encode([
            'total' => (int)$userInfo['quota_total'],
            'used' => (int)$userInfo['quota_used'],
            'percent' => (float)$percent
        ], JSON_PRETTY_PRINT));
        
        return $response->withHeader('Content-Type', 'application/json');
    }

    // ============================================
    // JOUR 4 - VERSIONING
    // ============================================

    /**
     * POST /files/{id}/versions - Upload une nouvelle version
     */
    public function uploadVersion(Request $request, Response $response, array $args): Response
    {
        if (!isset($this->versions)) {
            $response->getBody()->write(json_encode(['error' => 'Versioning not enabled']));
            return $response->withStatus(501)->withHeader('Content-Type', 'application/json');
        }

        $fileId = (int)$args['id'];
        $user = $request->getAttribute('user');
        $userId = $user['user_id'];

        // Vérifier que le fichier existe et appartient à l'utilisateur
        $file = $this->files->find($fileId);
        
        if (!$file || $file['user_id'] !== $userId) {
            $response->getBody()->write(json_encode(['error' => 'File not found or access denied']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        // Récupérer le fichier uploadé
        $uploadedFiles = $request->getUploadedFiles();
        
        if (!isset($uploadedFiles['file'])) {
            $response->getBody()->write(json_encode(['error' => 'No file uploaded']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $uploadedFile = $uploadedFiles['file'];
        
        if ($uploadedFile->getError() !== UPLOAD_ERR_OK) {
            $response->getBody()->write(json_encode(['error' => 'Upload failed']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        // Vérifier le quota
        $userInfo = $this->users->find($userId);
        $newFileSize = $uploadedFile->getSize();

        if (($userInfo['quota_used'] + $newFileSize) > $userInfo['quota_total']) {
            $response->getBody()->write(json_encode([
                'error' => 'Quota exceeded',
                'current_usage' => $userInfo['quota_used'],
                'quota' => $userInfo['quota_total']
            ]));
            return $response->withStatus(413)->withHeader('Content-Type', 'application/json');
        }

        // Générer un nom unique pour le stockage
        $extension = pathinfo($file['filename'], PATHINFO_EXTENSION);
        $storedName = uniqid('v_', true) . '.' . $extension;
        $targetPath = $this->uploadDir . DIRECTORY_SEPARATOR . $storedName;

        // Déplacer le fichier
        $uploadedFile->moveTo($targetPath);

        // Calculer le checksum
        $checksum = hash_file('sha256', $targetPath);

        // Créer la nouvelle version
        $versionData = [
            'stored_name' => $storedName,
            'size' => $newFileSize,
            'checksum' => $checksum,
            'mime_type' => $uploadedFile->getClientMediaType()
        ];

        $versionId = $this->versions->create($fileId, $versionData);

        if (!$versionId) {
            if (file_exists($targetPath)) {
                unlink($targetPath);
            }
            
            $response->getBody()->write(json_encode(['error' => 'Failed to create version']));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }

        // Mettre à jour le quota
        $this->users->updateQuota($userId, $userInfo['quota_used'] + $newFileSize);

        // Récupérer la version créée
        $version = $this->versions->getById($versionId);
        
        $response->getBody()->write(json_encode([
            'message' => 'New version uploaded successfully',
            'version' => [
                'id' => $version['id'],
                'file_id' => $fileId,
                'version' => $version['version'],
                'size' => $version['size'],
                'checksum' => $version['checksum'],
                'created_at' => $version['created_at']
            ]
        ]));

        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }

    /**
     * GET /files/{id}/versions - Liste toutes les versions
     */
    public function listVersions(Request $request, Response $response, array $args): Response
    {
        if (!isset($this->versions)) {
            $response->getBody()->write(json_encode(['error' => 'Versioning not enabled']));
            return $response->withStatus(501)->withHeader('Content-Type', 'application/json');
        }

        $fileId = (int)$args['id'];
        $user = $request->getAttribute('user');
        $params = $request->getQueryParams();

        $file = $this->files->find($fileId);
        
        if (!$file || $file['user_id'] !== $user['user_id']) {
            $response->getBody()->write(json_encode(['error' => 'File not found or access denied']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $limit = isset($params['limit']) ? (int)$params['limit'] : 50;
        $offset = isset($params['offset']) ? (int)$params['offset'] : 0;

        $versions = $this->versions->listByFile($fileId, $limit, $offset);
        $total = $this->versions->countByFile($fileId);

        $response->getBody()->write(json_encode([
            'file_id' => $fileId,
            'filename' => $file['filename'],
            'current_version' => $file['current_version'] ?? 1,
            'versions' => $versions,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * GET /files/{id}/versions/{version}/download - Télécharger une version spécifique
     */
    public function downloadVersion(Request $request, Response $response, array $args): Response
    {
        if (!isset($this->versions)) {
            $response->getBody()->write(json_encode(['error' => 'Versioning not enabled']));
            return $response->withStatus(501)->withHeader('Content-Type', 'application/json');
        }

        $fileId = (int)$args['id'];
        $versionNumber = (int)$args['version'];
        $user = $request->getAttribute('user');

        $file = $this->files->find($fileId);
        
        if (!$file || $file['user_id'] !== $user['user_id']) {
            $response->getBody()->write(json_encode(['error' => 'File not found or access denied']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $version = $this->versions->getByVersion($fileId, $versionNumber);
        
        if (!$version) {
            $response->getBody()->write(json_encode(['error' => 'Version not found']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $filePath = $this->uploadDir . DIRECTORY_SEPARATOR . $version['stored_name'];

        if (!file_exists($filePath)) {
            $response->getBody()->write(json_encode(['error' => 'File not found on server']));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }

        $filename = $file['filename'];
        $filenameWithVersion = pathinfo($filename, PATHINFO_FILENAME) 
            . '_v' . $version['version'] 
            . '.' . pathinfo($filename, PATHINFO_EXTENSION);

        $stream = fopen($filePath, 'rb');
        $response->getBody()->write(stream_get_contents($stream));
        fclose($stream);

        return $response
            ->withHeader('Content-Type', 'application/octet-stream')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filenameWithVersion . '"')
            ->withHeader('Content-Length', filesize($filePath));
    }
}
