<?php
namespace App\Controllers;

use App\Services\UnitService;
use Exception;

class UnitController extends BaseController {
    private UnitService $unitService;

    public function __construct(?UnitService $unitService = null) {
        $this->unitService = $unitService ?: new UnitService();
    }

    public function index(): void {
        $filters = [
            'tower' => $_GET['tower'] ?? null,
            'status' => $_GET['status'] ?? null,
            'floor' => $_GET['floor'] ?? null,
            'search' => $_GET['q'] ?? null,
        ];

        $units = $this->unitService->getFlats($filters);
        $this->json([
            'success' => true,
            'total' => count($units),
            'data' => $units
        ]);
    }

    public function show(string $unitCode): void {
        $unit = $this->unitService->getUnitPassport($unitCode);
        if (!$unit) {
            $this->error("Unit {$unitCode} not found", 404);
            return;
        }
        $this->success($unit);
    }

    public function create(): void {
        $input = $this->getJsonInput();
        try {
            $created = $this->unitService->createUnit($input);
            $this->success($created, 'Unit created successfully', 201);
        } catch (Exception $e) {
            $this->error($e->getMessage(), 400);
        }
    }

    public function bulkGenerate(): void {
        $input = $this->getJsonInput();
        try {
            $result = $this->unitService->bulkGenerateUnits($input);
            $this->success($result, 'Units generated successfully', 201);
        } catch (Exception $e) {
            $this->error($e->getMessage(), 400);
        }
    }

    public function batchCreate(): void {
        $input = $this->getJsonInput();
        try {
            $result = $this->unitService->batchCreateUnits($input);
            $this->success($result, 'Batch floor units created successfully', 201);
        } catch (Exception $e) {
            $this->error($e->getMessage(), 400);
        }
    }
}
