<?php
namespace App\Controllers;

use App\Services\UnitTypeService;
use App\Config\RbacGuard;
use Exception;
use InvalidArgumentException;

class UnitTypeController extends BaseController {
    private UnitTypeService $service;

    public function __construct(?UnitTypeService $service = null) {
        $this->service = $service ?: new UnitTypeService();
    }

    public function index(): void {
        try {
            $types = $this->service->getUnitTypes();
            $this->json(['data' => $types]);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    public function create(): void {
        try {
            RbacGuard::requirePermission('units.create');
            $data = $this->getJsonInput();
            $result = $this->service->createUnitType($data);
            $this->json(['message' => 'Custom unit type created successfully', 'data' => $result], 201);
        } catch (InvalidArgumentException $e) {
            $this->json(['error' => $e->getMessage()], 400);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    public function delete(int $id): void {
        try {
            RbacGuard::requirePermission('units.create');
            $this->service->deleteUnitType($id);
            $this->json(['message' => 'Unit type removed successfully']);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 400);
        }
    }
}