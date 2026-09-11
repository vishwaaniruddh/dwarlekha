<?php
namespace App\Controllers;

use App\Utils\Logger;

abstract class BaseController {
    protected ?array $cachedInput = null;

    protected function json(array $data, int $statusCode = 200): void {
        $start = defined('APP_START_TIME') ? APP_START_TIME : microtime(true);
        $durationMs = (microtime(true) - $start) * 1000;
        $route = $_GET['route'] ?? 'unknown';
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        
        Logger::logRequest($method, $route, $this->cachedInput, $statusCode, $data, $durationMs);

        // Automatically log every API mutation or HTTP error to AuditService
        if (($method !== 'GET' || $statusCode >= 400) && !str_starts_with($route, 'audit')) {
            $sanitizedInput = $this->cachedInput;
            if (is_array($sanitizedInput)) {
                if (isset($sanitizedInput['password'])) $sanitizedInput['password'] = '******';
                if (isset($sanitizedInput['password_hash'])) $sanitizedInput['password_hash'] = '******';
            }
            \App\Services\AuditService::log(
                action: "API_{$method}:/{$route}",
                entityType: 'api_request',
                entityId: (string)$statusCode,
                details: [
                    'method' => $method,
                    'route' => $route,
                    'status' => $statusCode,
                    'duration_ms' => round($durationMs, 2),
                    'payload' => $sanitizedInput,
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
                ]
            );
        }

        if (!headers_sent()) {
            http_response_code($statusCode);
        }
        echo json_encode($data);
    }

    protected function success(mixed $data = null, string $message = 'Success', int $statusCode = 200): void {
        $response = ['success' => true, 'message' => $message];
        if ($data !== null) {
            $response['data'] = $data;
        }
        $this->json($response, $statusCode);
    }

    protected function error(string $message, int $statusCode = 400): void {
        $this->json([
            'success' => false,
            'error' => $message
        ], $statusCode);
    }

    protected function getJsonInput(): array {
        if ($this->cachedInput !== null) {
            return $this->cachedInput;
        }
        $raw = file_get_contents('php://input');
        $this->cachedInput = json_decode($raw, true) ?: [];
        return $this->cachedInput;
    }
}

