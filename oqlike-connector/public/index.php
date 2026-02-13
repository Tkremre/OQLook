<?php

declare(strict_types=1);

use OQLikeConnector\ItopMetaModelService;

ob_start();

register_shutdown_function(static function (): void {
    $error = error_get_last();

    if (! is_array($error)) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

    if (! in_array((int) ($error['type'] ?? 0), $fatalTypes, true)) {
        return;
    }

    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    if (! headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }

    echo json_encode([
        'ok' => false,
        'error' => (string) ($error['message'] ?? 'Fatal error'),
        'fatal' => [
            'type' => (int) ($error['type'] ?? 0),
            'file' => (string) ($error['file'] ?? ''),
            'line' => (int) ($error['line'] ?? 0),
        ],
    ], JSON_UNESCAPED_SLASHES);
});

require_once __DIR__.'/../src/ItopMetaModelService.php';

$configPath = __DIR__.'/../config.php';

if (! file_exists($configPath)) {
    $configPath = __DIR__.'/../config.sample.php';
}

$config = require $configPath;

if (isset($config['max_execution_seconds']) && is_numeric($config['max_execution_seconds'])) {
    @set_time_limit((int) $config['max_execution_seconds']);
}

$bootstrapPath = $config['itop_bootstrap'] ?? null;

if (is_string($bootstrapPath) && $bootstrapPath !== '') {
    $normalizedBootstrap = str_replace('\\', '/', $bootstrapPath);
    $appRootFromBootstrap = rtrim(dirname($normalizedBootstrap, 2), '/');

    if ($appRootFromBootstrap !== '' && ! defined('APPROOT')) {
        define('APPROOT', $appRootFromBootstrap.'/');
    }
}

header('Content-Type: application/json; charset=utf-8');

$origin = $_SERVER['HTTP_ORIGIN'] ?? null;
$allowedOrigins = $config['cors_allowed_origins'] ?? [];

if (is_string($origin) && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: '.$origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
$providedToken = '';

if (preg_match('/Bearer\s+(.+)/i', $authHeader, $matches) === 1) {
    $providedToken = trim($matches[1]);
}

$expectedToken = (string) ($config['bearer_token'] ?? '');

if ($expectedToken === '' || ! hash_equals($expectedToken, $providedToken)) {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'error' => 'Unauthorized',
    ]);
    exit;
}

$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php');
$basePath = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
$path = $uriPath;

if ($basePath !== '' && $basePath !== '/' && str_starts_with($path, $basePath)) {
    $path = substr($path, strlen($basePath)) ?: '/';
}

$path = '/'.ltrim($path, '/');
$path = preg_replace('#^/index\.php#', '', $path) ?: '/';

try {
    $service = new ItopMetaModelService($config);

    if ($path === '/ping') {
        echo json_encode($service->ping(), JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($path === '/classes') {
        $filter = $_GET['filter'] ?? 'persistent';
        $includeHash = filter_var($_GET['include_hash'] ?? '1', FILTER_VALIDATE_BOOLEAN);

        echo json_encode([
            'ok' => true,
            'metamodel_hash' => $includeHash ? $service->getGlobalMetamodelHash() : null,
            'classes' => $service->classes((string) $filter),
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if (preg_match('#^/class/([^/]+)$#', $path, $matches) === 1) {
        $className = urldecode($matches[1]);
        echo json_encode($service->classPayload($className), JSON_UNESCAPED_SLASHES);
        exit;
    }

    if (preg_match('#^/class/([^/]+)/relations$#', $path, $matches) === 1) {
        $className = urldecode($matches[1]);
        echo json_encode($service->classRelations($className), JSON_UNESCAPED_SLASHES);
        exit;
    }

    http_response_code(404);
    echo json_encode([
        'ok' => false,
        'error' => 'Not found',
    ]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $exception->getMessage(),
        'exception_class' => $exception::class,
        'debug' => [
            'bootstrap_path' => $bootstrapPath ?? null,
            'approot_defined' => defined('APPROOT'),
            'approot' => defined('APPROOT') ? APPROOT : null,
        ],
    ]);
}
