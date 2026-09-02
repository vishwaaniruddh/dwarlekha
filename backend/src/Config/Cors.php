<?php
namespace App\Config;

class Cors {
    public static function handle(): void {
        if (!headers_sent()) {
            $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
            header("Access-Control-Allow-Origin: {$origin}");
            header("Access-Control-Allow-Credentials: true");
            header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
            header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Society-ID, X-Tenant-ID");
            header("Content-Type: application/json; charset=UTF-8");
        }

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            if (!headers_sent()) {
                http_response_code(200);
            }
            exit;
        }
    }
}
