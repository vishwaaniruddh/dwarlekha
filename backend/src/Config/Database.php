<?php
namespace App\Config;

use PDO;
use PDOException;

class Database {
    private static ?PDO $instance = null;

    public static function getConnection(): PDO {
        if (self::$instance === null) {
            $configFile = __DIR__ . '/../../config/database.php';
            $config = [];
            if (file_exists($configFile)) {
                $config = require $configFile;
            }

            $host = $config['host'] ?? getenv('DB_HOST') ?: '127.0.0.1';
            $port = $config['port'] ?? getenv('DB_PORT') ?: '3306';
            $db   = $config['database'] ?? getenv('DB_NAME') ?: 'society_management_program';
            $user = $config['username'] ?? getenv('DB_USER') ?: 'root';
            $pass = $config['password'] ?? getenv('DB_PASS') ?: '';
            $charset = 'utf8mb4';

            $dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
            ];

            try {
                self::$instance = new PDO($dsn, $user, $pass, $options);
            } catch (PDOException $e) {
                http_response_code(500);
                header("Access-Control-Allow-Origin: *");
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'error' => 'Database connection failed: ' . $e->getMessage()
                ], JSON_PRETTY_PRINT);
                exit;
            }
        }
        return self::$instance;
    }

    public static function setConnection(?PDO $pdo): void {
        self::$instance = $pdo;
    }
}
