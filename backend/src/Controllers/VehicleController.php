<?php
namespace App\Controllers;

use App\Models\Vehicle;
use App\Config\TenantContext;
use Exception;

class VehicleController extends BaseController {
    private Vehicle $vehicleModel;

    public function __construct(?Vehicle $vehicleModel = null) {
        $this->vehicleModel = $vehicleModel ?: new Vehicle();
    }

    public function index(): void {
        $societyId = TenantContext::getSocietyId();
        $filters = [];
        if (!empty($_GET['unit_id'])) {
            $filters['unit_id'] = (int)$_GET['unit_id'];
        }
        if (!empty($_GET['resident_id'])) {
            $filters['resident_id'] = (int)$_GET['resident_id'];
        }
        if (!empty($_GET['vehicle_type'])) {
            $filters['vehicle_type'] = $_GET['vehicle_type'];
        }
        if (!empty($_GET['pass_status'])) {
            $filters['pass_status'] = $_GET['pass_status'];
        }

        $vehicles = $this->vehicleModel->getAll($societyId, $filters);
        $this->success($vehicles);
    }

    public function show(int $id): void {
        $societyId = TenantContext::getSocietyId();
        $vehicle = $this->vehicleModel->findById($id, $societyId);
        if (!$vehicle) {
            $this->error("Vehicle not found", 404);
            return;
        }
        $this->success($vehicle);
    }

    public function create(): void {
        $input = $this->getJsonInput();
        if (empty($input['vehicle_number']) || empty($input['unit_id'])) {
            $this->error("Vehicle number and Unit ID are required.", 400);
            return;
        }
        try {
            $societyId = (int)($input['society_id'] ?? TenantContext::getSocietyId());
            $id = $this->vehicleModel->create($input, $societyId);
            $vehicle = $this->vehicleModel->findById($id, $societyId);
            $this->success($vehicle, "Vehicle registered successfully", 201);
        } catch (Exception $e) {
            $this->error($e->getMessage(), 400);
        }
    }

    public function update(int $id): void {
        $input = $this->getJsonInput();
        $societyId = TenantContext::getSocietyId();
        try {
            $this->vehicleModel->update($id, $input, $societyId);
            $vehicle = $this->vehicleModel->findById($id, $societyId);
            $this->success($vehicle, "Vehicle updated successfully");
        } catch (Exception $e) {
            $this->error($e->getMessage(), 400);
        }
    }

    public function delete(int $id): void {
        $societyId = TenantContext::getSocietyId();
        try {
            $this->vehicleModel->delete($id, $societyId);
            $this->success(null, "Vehicle deleted successfully");
        } catch (Exception $e) {
            $this->error($e->getMessage(), 400);
        }
    }
}
