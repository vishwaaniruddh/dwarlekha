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

