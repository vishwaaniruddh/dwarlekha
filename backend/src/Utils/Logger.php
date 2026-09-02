<?php
namespace App\Utils;

class Logger {
    private static string $logDir = __DIR__ . '/../../logs';

    private static function ensureLogDir(): void {
        if (!is_dir(self::$logDir)) {
            @mkdir(self::$logDir, 0777, true);
        }
    }

    public static function logRequest(string $method, string $route, mixed $payload = null, ?int $statusCode = null, mixed $response = null, float $durationMs = 0.0): void {
        if (!getenv('ENABLE_REQUEST_LOGGING')) {
            return; // Disabled by default to prevent disk bloating
        }
        self::ensureLogDir();
        $file = self::$logDir . '/api_requests.log';
        
        $entry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            'method' => $method,
            'route' => $route,
            'society_header' => $_SERVER['HTTP_X_SOCIETY_ID'] ?? ($_GET['society_code'] ?? 'GLOBAL'),
            'request_payload' => $payload,
            'status_code' => $statusCode,
            'response' => $response,
            'duration_ms' => round($durationMs, 2)
        ];

        @file_put_contents($file, json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    }

    public static function logError(string $message, ?\Throwable $exception = null, mixed $context = null): void {
        self::ensureLogDir();
        $file = self::$logDir . '/app_errors.log';

        $entry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'message' => $message,
            'context' => $context,
            'exception' => $exception ? [
                'type' => get_class($exception),
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString()
            ] : null
        ];

        @file_put_contents($file, json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    }

    public static function getRecentLogs(int $limit = 50): array {
        self::ensureLogDir();
        $file = self::$logDir . '/api_requests.log';
        if (!file_exists($file)) {
            return [];
        }

        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) return [];

        $slice = array_slice($lines, -$limit);
        $result = [];
        foreach (array_reverse($slice) as $line) {
            $decoded = json_decode($line, true);
            if ($decoded) {
                $result[] = $decoded;
            }
        }
        return $result;
    }
}
