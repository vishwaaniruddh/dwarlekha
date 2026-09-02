<?php
namespace App\Controllers;

use App\Services\RoleService;
use App\Config\RbacGuard;
use Exception;

class RoleController extends BaseController {
    private RoleService $roleService;

    public function __construct(?RoleService $roleService = null) {
        $this->roleService = $roleService ?: new RoleService();
    }

    public function index(): void {
        RbacGuard::requirePermission('roles.view');

        try {
            $roles = $this->roleService->getRoles();
            $this->success($roles);
        } catch (Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }

    public function matrix(): void {
        RbacGuard::requirePermission('roles.view');

        try {
            $matrix = $this->roleService->getRolePermissionMatrix();
            $this->success($matrix);
        } catch (Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }

    public function permissions(): void {
        RbacGuard::requirePermission('roles.view');

        try {
            $perms = $this->roleService->getPermissionsCatalog();
            $this->success($perms);
        } catch (Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }

    public function create(): void {
        RbacGuard::requirePermission('roles.manage');

        $input = $this->getJsonInput();
        try {
            $role = $this->roleService->createRole($input);
            $this->success($role, "Role {$role['name']} created successfully", 201);
        } catch (Exception $e) {
            $this->error($e->getMessage(), 400);
        }
    }

    public function updatePermissions(string $id): void {
        RbacGuard::requirePermission('roles.manage');

        $input = $this->getJsonInput();
        try {
            $permissionIds = $input['permissionIds'] ?? [];
            $role = $this->roleService->updateRolePermissions((int)$id, $permissionIds);
            $this->success($role, "Role permissions updated successfully");
        } catch (Exception $e) {
            $this->error($e->getMessage(), 400);
        }
    }
}
