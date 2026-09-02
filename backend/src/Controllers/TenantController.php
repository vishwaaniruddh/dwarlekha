<?php
namespace App\Controllers;

use App\Services\TenantService;
use Exception;

class TenantController extends BaseController {
    private TenantService $tenantService;

    public function __construct(?TenantService $tenantService = null) {
        $this->tenantService = $tenantService ?: new TenantService();
    }

    public function listSocieties(): void {
        $societies = $this->tenantService->getAllSocieties();
        $this->success($societies);
    }

    public function createSociety(): void {
        $input = $this->getJsonInput();
        try {
            $created = $this->tenantService->createSociety($input);
            $this->success($created, 'Society registered successfully', 201);
        } catch (Exception $e) {
            $this->error($e->getMessage(), 400);
        }
    }

    public function updateSociety(int $id): void {
        $input = $this->getJsonInput();
        try {
            $updated = $this->tenantService->updateSociety($id, $input);
            $this->success($updated, 'Society details updated successfully');
        } catch (Exception $e) {
            $this->error($e->getMessage(), 400);
        }
    }
}
