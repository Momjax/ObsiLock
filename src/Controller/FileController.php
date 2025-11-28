<?php
namespace App\Controller;

use App\Model\FileRepository;
use App\Model\UserRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class FileController
{
    private FileRepository $files;
    private UserRepository $users;
    private string $uploadDir;

    public function __construct(FileRepository $files, UserRepository $users, string $uploadDir)
    {
        $this->files = $files;
        $this->users = $users;
        $this->uploadDir = $uploadDir;
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
        $file->moveTo($this->uploadDir . DIRECTORY_SEPARATOR . $storedName);

        // Créer l'entrée en BDD
        $fileId = $this->files->create([
            'user_id' => $user['user_id'],
            'folder_id' => null,
            'filename' => $originalName,
            'stored_name' => $storedName,
            'size' => $size,
            'mime_type' => $mimeType
        ]);

        // Mettre à jour le quota
        $this->users->updateQuota($user['user_id'], $userInfo['quota_used'] + $size);

        $response->getBody()->write(json_encode([
            'message' => 'Fichier uploadé',
            'id' => $fileId
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

        // Supprimer de la BDD
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
}