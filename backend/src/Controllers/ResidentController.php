<?php
namespace App\Controllers;

use App\Services\ResidentService;
use Exception;
use InvalidArgumentException;

class ResidentController extends BaseController {
    private ResidentService $residentService;

    public function __construct(?ResidentService $residentService = null) {
        $this->residentService = $residentService ?: new ResidentService();
    }

    public function index(): void {
        $filters = [
            'tower' => $_GET['tower'] ?? null,
            'resident_type' => $_GET['resident_type'] ?? ($_GET['type'] ?? null),
            'verification_status' => $_GET['status'] ?? null,
            'search' => $_GET['q'] ?? ($_GET['search'] ?? null)
        ];
        $residents = $this->residentService->getResidents($filters);
        $this->success($residents);
    }

    public function show(int $id): void {
        $resident = $this->residentService->getResidentPassport($id);
        if (!$resident) {
            $this->error("Resident not found", 404);
            return;
        }
        $this->success($resident);
    }

    public function onboard(): void {
        $input = $this->getJsonInput();
        try {
            $allotted = $this->residentService->onboard($input);
            $this->success($allotted, "Resident {$allotted['name']} successfully allotted to Unit {$allotted['flatNumber']}", 201);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }

    public function verify(int $id): void {
        $input = $this->getJsonInput();
        $status = $input['status'] ?? 'Approved';
        $rejectionReason = $input['rejection_reason'] ?? null;
        $verifiedBy = $input['verified_by_user_id'] ?? null;

        try {
            $this->residentService->verifyResident($id, $status, $rejectionReason, $verifiedBy);
            $resident = $this->residentService->getResidentPassport($id);
            $this->success($resident, "Resident verification status updated to {$status}");
        } catch (Exception $e) {
            $this->error($e->getMessage(), 400);
        }
    }

    public function addFamily(int $id): void {
        $input = $this->getJsonInput();
        try {
            $member = $this->residentService->addFamilyMember($id, $input);
            $this->success($member, "Family member added successfully", 201);
        } catch (Exception $e) {
            $this->error($e->getMessage(), 400);
        }
    }

    public function deleteFamily(int $id, int $memberId): void {
        try {
            $this->residentService->deleteFamilyMember($memberId);
            $this->success(null, "Family member removed successfully");
        } catch (Exception $e) {
            $this->error($e->getMessage(), 400);
        }
    }

    public function addDocument(int $id): void {
        $input = $this->getJsonInput();
        try {
            $doc = $this->residentService->addDocument($id, $input);
            $this->success($doc, "Document uploaded successfully", 201);
        } catch (Exception $e) {
            $this->error($e->getMessage(), 400);
        }
    }

    public function deleteDocument(int $id, int $docId): void {
        try {
            $this->residentService->deleteDocument($docId);
            $this->success(null, "Document removed successfully");
        } catch (Exception $e) {
            $this->error($e->getMessage(), 400);
        }
    }

    public function addVehicle(int $id): void {
        $input = $this->getJsonInput();
        try {
            $veh = $this->residentService->addVehicle($id, $input);
            $this->success($veh, "Vehicle registered successfully", 201);
        } catch (Exception $e) {
            $this->error($e->getMessage(), 400);
        }
    }

    public function deleteVehicle(int $id, int $vehId): void {
        try {
            $this->residentService->deleteVehicle($vehId);
            $this->success(null, "Vehicle removed successfully");
        } catch (Exception $e) {
            $this->error($e->getMessage(), 400);
        }
    }

    public function delete(int $id): void {
        try {
            $this->residentService->deleteResident($id);
            $this->success(null, "Resident record permanently deleted and unit occupancy recalculated.");
        } catch (Exception $e) {
            $this->error($e->getMessage(), 400);
        }
    }

    public function updateType(int $id): void {
        $input = $this->getJsonInput();
        $newType = $input['resident_type'] ?? ($input['type'] ?? null);
        if (!$newType) {
            $this->error("resident_type is required (Owner or Tenant).", 400);
            return;
        }
        try {
            $updated = $this->residentService->updateResidentType($id, $newType);
            $this->success($updated, "Resident type changed to {$newType}");
        } catch (Exception $e) {
            $this->error($e->getMessage(), 400);
        }
    }

    public function credentials(int $id): void {
        $input = $this->getJsonInput();
        $password = $input['password'] ?? null;
        try {
            $creds = $this->residentService->getOrResetCredentials($id, $password);
            $this->success($creds, "Login credentials generated successfully");
        } catch (Exception $e) {
            $this->error($e->getMessage(), 400);
        }
    }
}