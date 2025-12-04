<?php
use Slim\Factory\AppFactory;
use Medoo\Medoo;
use App\Controller\AuthController;
use App\Controller\FolderController;
use App\Controller\FileController;
use App\Model\UserRepository;
use App\Model\FolderRepository;
use App\Model\FileRepository;
use App\Controller\ShareController;

require __DIR__ . '/../vendor/autoload.php';

// Charger le fichier .env
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
            list($key, $value) = explode('=', $line, 2);
            putenv(trim($key) . '=' . trim($value));
        }
    }
}

// Connexion BDD
$database = new Medoo([
    'type' => 'mysql',
    'host' => getenv('DB_HOST') ?: '127.0.0.1',
    'database' => getenv('DB_NAME') ?: 'file_vault',
    'username' => getenv('DB_USER') ?: 'root',
    'password' => getenv('DB_PASS') ?: '',
]);

$jwtSecret = getenv('JWT_SECRET') ?: 'changez_moi_secret_32_chars_min';
$uploadDir = __DIR__ . '/../storage/uploads';

// Repositories
$userRepo = new UserRepository($database);
$folderRepo = new FolderRepository($database);
$fileRepo = new FileRepository($database);

// Controllers
$authController = new AuthController($userRepo, $jwtSecret);
$folderController = new FolderController($folderRepo);
$fileController = new FileController($fileRepo, $userRepo, $uploadDir);

// Slim App
$app = AppFactory::create();
$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();

// Middleware CORS simple
$app->add(function ($request, $handler) {
    $response = $handler->handle($request);
    return $response
        ->withHeader('Access-Control-Allow-Origin', '*')
        ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
});

// Middleware Auth JWT (pour les routes protégées)
$authMiddleware = function ($request, $handler) use ($jwtSecret) {
    $authHeader = $request->getHeaderLine('Authorization');
    
    if (empty($authHeader) || !preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        $response = new \Slim\Psr7\Response();
        $response->getBody()->write(json_encode(['error' => 'Non autorisé']));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
    }

    $token = $matches[1];
    $parts = explode('.', $token);
    
    if (count($parts) !== 3) {
        $response = new \Slim\Psr7\Response();
        $response->getBody()->write(json_encode(['error' => 'Token invalide']));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
    }

    [$header, $payload, $signature] = $parts;
    
    // Vérifier la signature
    $expectedSignature = hash_hmac('sha256', "$header.$payload", $jwtSecret, true);
    $expectedSignature = rtrim(strtr(base64_encode($expectedSignature), '+/', '-_'), '=');
    
    if ($signature !== $expectedSignature) {
        $response = new \Slim\Psr7\Response();
        $response->getBody()->write(json_encode(['error' => 'Signature invalide']));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
    }

    // Décoder le payload
    $payloadData = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
    
    if (!isset($payloadData['exp']) || $payloadData['exp'] < time()) {
        $response = new \Slim\Psr7\Response();
        $response->getBody()->write(json_encode(['error' => 'Token expiré']));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
    }

    // Ajouter l'utilisateur dans la requête
    $request = $request->withAttribute('user', $payloadData);
    
    return $handler->handle($request);
};

// Auto-détection base path
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$basePath = rtrim(str_ireplace('index.php', '', $scriptName), '/');
if ($basePath !== '') {
    $app->setBasePath($basePath);
}

// ==================== ROUTES ====================

// Route d'accueil
$app->get('/', function ($request, $response) {
    $response->getBody()->write(json_encode([
        'message' => 'File Vault API - Jours 1 & 2',
        'endpoints' => [
            'POST /auth/register',
            'POST /auth/login',
            'GET /folders (auth)',
            'POST /folders (auth)',
            'DELETE /folders/{id} (auth)',
            'GET /files (auth)',
            'POST /files (auth)',
            'GET /files/{id} (auth)',
            'GET /files/{id}/download (auth)',
            'DELETE /files/{id} (auth)',
            'GET /stats (auth)',
        ]
    ], JSON_PRETTY_PRINT));
    return $response->withHeader('Content-Type', 'application/json');
});

// Auth (publiques)
$app->post('/auth/register', [$authController, 'register']);
$app->post('/auth/login', [$authController, 'login']);

// Folders (protégées)
$app->get('/folders', [$folderController, 'list'])->add($authMiddleware);
$app->post('/folders', [$folderController, 'create'])->add($authMiddleware);
$app->delete('/folders/{id}', [$folderController, 'delete'])->add($authMiddleware);

// Files (protégées)
$app->get('/files', [$fileController, 'list'])->add($authMiddleware);
$app->post('/files', [$fileController, 'upload'])->add($authMiddleware);
$app->get('/files/{id}', [$fileController, 'show'])->add($authMiddleware);
$app->get('/files/{id}/download', [$fileController, 'download'])->add($authMiddleware);
$app->delete('/files/{id}', [$fileController, 'delete'])->add($authMiddleware);

// Me (protégées)
$app->get('/me/quota', [$fileController, 'quota'])->add($authMiddleware);

// Stats (protégée)
$app->get('/stats', [$fileController, 'stats'])->add($authMiddleware);

$errorMiddleware = $app->addErrorMiddleware(true, true, true);

// ============================================
// ROUTES JOUR 3 - Partages
// ============================================



$shareController = new ShareController($database);

// Créer un partage (protégée)
$app->post('/shares', [$shareController, 'create'])->add($authMiddleware);

// Lister mes partages (protégée)
$app->get('/shares', [$shareController, 'list'])->add($authMiddleware);

// Révoquer un partage (protégée)
$app->post('/shares/{id}/revoke', [$shareController, 'revoke'])->add($authMiddleware);

// Routes publiques (sans authMiddleware)
$app->get('/s/{token}', [$shareController, 'getPublicMetadata']);
$app->post('/s/{token}/download', [$shareController, 'downloadPublic']);
$app->get('/s/{token}/download', [$shareController, 'downloadPublic']);

$app->run();