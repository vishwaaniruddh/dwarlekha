<?php
/**
 * Database Configuration for DwarLekha Platform
 * Automatically detects Local XAMPP vs Live Production Server (dwarlekha.sarsspl.com)
 */

$serverHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
$isLocal = (
    php_sapi_name() === 'cli' && (getenv('APP_ENV') !== 'production') && (PHP_OS_FAMILY === 'Windows')
) || (
    !empty($serverHost) && (
        str_contains($serverHost, 'localhost') || 
        str_contains($serverHost, '127.0.0.1')
    )
);

if ($isLocal) {
    // 🖥️ Local Development (XAMPP MySQL)
    return [
        'host'     => getenv('DB_HOST') ?: '127.0.0.1',
        'port'     => getenv('DB_PORT') ?: '3306',
        'database' => getenv('DB_NAME') ?: 'society_management_program',
        'username' => getenv('DB_USER') ?: 'root',
        'password' => getenv('DB_PASS') ?: '',
    ];
}

// 🌐 Production Server (dwarlekha.sarsspl.com)
return [
    'host'     => getenv('DB_HOST') ?: 'localhost',
    'port'     => getenv('DB_PORT') ?: '3306',
    'database' => getenv('DB_NAME') ?: 'u444388293_dwarlekha',
    'username' => getenv('DB_USER') ?: 'u444388293_dwarlekha',
    'password' => getenv('DB_PASS') ?: 'AVav@@2026',
];
