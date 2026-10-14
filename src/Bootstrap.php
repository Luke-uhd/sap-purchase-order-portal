<?php
declare(strict_types=1);

namespace App;

$configFile = dirname(__DIR__) . '/config/app.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('Configuração ausente. Copie config/app.php.example para config/app.php.');
}
$GLOBALS['app_config'] = require $configFile;
$GLOBALS['app_config']['storage_path'] = require dirname(__DIR__) . '/config/storage.php';

require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/ServiceLayerClient.php';
require_once __DIR__ . '/BusinessCalendar.php';
require_once __DIR__ . '/PurchaseOrderService.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name((string)app_config('session_name'));
    ini_set('session.use_cookies', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function app_config(?string $key = null): mixed {
    $config = $GLOBALS['app_config'];
    return $key === null ? $config : ($config[$key] ?? null);
}
function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function json_response(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}
function positive_int(mixed $value, int $min, int $max): int {
    $n = filter_var($value, FILTER_VALIDATE_INT);
    return $n === false ? $min : max($min, min($n, $max));
}

function app_storage_dir(string $subdir): string {
    if (!in_array($subdir, ['config', 'logs'], true)) {
        throw new \RuntimeException('Subdiretório de storage inválido.', 500);
    }

    $root = app_config('storage_path');

    if (
        !is_string($root)
        || $root === ''
        || $root[0] !== '/'
        || !is_dir($root)
    ) {
        throw new \RuntimeException(
            'Diretório persistente de storage ausente ou inválido.',
            500
        );
    }

    $path = rtrim($root, '/') . '/' . $subdir;

    if (!is_dir($path)) {
        throw new \RuntimeException(
            'Subdiretório persistente de storage ausente: ' . $subdir,
            500
        );
    }

    return $path;
}

function app_log(string $event, array $context = []): void {
    $dir = app_storage_dir('logs');
    if (!is_dir($dir)) mkdir($dir, 0750, true);
    file_put_contents($dir . '/app.log', json_encode(['timestamp'=>date(DATE_ATOM),'event'=>$event,'context'=>$context], JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
}
function api_error(\Throwable $e): never {
    $status = (int)$e->getCode();
    if ($status < 400 || $status > 599) $status = 500;
    app_log('api_error', ['message'=>$e->getMessage(),'code'=>$status]);
    json_response(['ok'=>false,'erro'=>$e->getMessage()], $status);
}
