<?php
namespace App\Controllers;

use App\Services\UserService;
use App\Config\RbacGuard;
use Exception;

class UserController extends BaseController {
    private UserService $userService;

    public function __construct(?UserService $userService = null) {
        $this->userService = $userService ?: new UserService();
    }

    public function index(): void {
        RbacGuard::requirePermission('users.view');
        
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : null;
        $limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : null;

        $filters = [
            'role' => $_GET['role'] ?? '',
            'status' => $_GET['status'] ?? '',
            'search' => $_GET['q'] ?? ($_GET['search'] ?? ''),
            'page' => $page,
            'limit' => $limit
        ];

        try {
            $users = $this->userService->getUsers($filters);
            $this->success($users);
        } catch (Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }

    public function show(string $id): void {
        RbacGuard::requirePermission('users.view');
        
        try {
            $user = $this->userService->getUserById((int)$id);
            if ($user) {
                $this->success($user);
            } else {
                $this->error('User not found', 404);
            }
        } catch (Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }

    public function create(): void {
        RbacGuard::requirePermission('users.create');

        $input = $this->getJsonInput();
        try {
            $user = $this->userService->createUser($input);
            $this->success($user, "User {$user['fullName']} created successfully", 201);
        } catch (Exception $e) {
            $this->error($e->getMessage(), 400);
        }
    }

    public function update(string $id): void {
        RbacGuard::requirePermission('users.edit');

        $input = $this->getJsonInput();
        try {
            $user = $this->userService->updateUser((int)$id, $input);
            $this->success($user, "User {$user['fullName']} updated successfully");
        } catch (Exception $e) {
            $this->error($e->getMessage(), 400);
        }
    }

    public function delete(string $id): void {
        RbacGuard::requirePermission('users.delete');

        try {
            $this->userService->deleteUser((int)$id);
            $this->success(null, "User deleted successfully");
        } catch (Exception $e) {
            $this->error($e->getMessage(), 400);
        }
    }
}
