<?php
/**
 * Public Front Controller - DwarLekha Platform
 */

// Error handling - catch and return JSON
error_reporting(E_ALL);
ini_set('display_errors', '0');

// Global CORS Headers
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Society-ID, Accept");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 1. Try Composer autoloader first
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
} else {
    // 2. Resilient Native PSR-4 Autoloader (Zero-dependency fallback for Git clones)
    spl_autoload_register(function ($class) {
        $prefix = 'App\\';
        $baseDir = __DIR__ . '/../src/';

        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }

        $relativeClass = substr($class, $len);
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

        if (file_exists($file)) {
            require_once $file;
        }
    });
}

try {
    $router = new \App\Router();
    $router->dispatch();
} catch (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine()
    ], JSON_PRETTY_PRINT);
}
