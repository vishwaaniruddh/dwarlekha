<?php
namespace App\Controllers;

use App\Services\AuthService;
use Exception;

class AuthController extends BaseController {
    private AuthService $authService;

    public function __construct(?AuthService $authService = null) {
        $this->authService = $authService ?: new AuthService();
    }

    public function login(): void {
        $input = $this->getJsonInput();
        try {
            $result = $this->authService->login($input['email'] ?? '', $input['password'] ?? '');
            $this->success($result, 'Login successful');
        } catch (Exception $e) {
            $this->error($e->getMessage(), 401);
        }
    }

    private function getAuthHeader(): ?string {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (!empty($headers['Authorization'])) return $headers['Authorization'];
            if (!empty($headers['authorization'])) return $headers['authorization'];
        }
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) return $_SERVER['HTTP_AUTHORIZATION'];
        if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) return $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        if (!empty($_GET['auth_token'])) return 'Bearer ' . $_GET['auth_token'];
        return null;
    }

    public function me(): void {
        $authHeader = $this->getAuthHeader();
        $user = $this->authService->validateToken($authHeader);
        if ($user) {
            $this->success($user, 'Authenticated user profile');
        } else {
            $this->error('Unauthenticated or expired session.', 401);
        }
    }

    public function logout(): void {
        $authHeader = $this->getAuthHeader();
        if ($authHeader) {
            $this->authService->logout($authHeader);
        }
        $this->success(null, 'Logged out successfully');
    }

    public function personas(): void {
        try {
            $personas = $this->authService->getPersonas();
            $this->success($personas);
        } catch (Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }
}
