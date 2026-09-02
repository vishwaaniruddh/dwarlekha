<?php
namespace App\Controllers;

use App\Services\AmenityService;
use App\Config\TenantContext;
use Exception;

class AmenityController extends BaseController {
    private AmenityService $amenityService;

    public function __construct(?AmenityService $amenityService = null) {
        $this->amenityService = $amenityService ?: new AmenityService();
    }

    public function index(): void {
        $societyId = TenantContext::getSocietyId();
        if (isset($_GET['society_id'])) {
            $societyId = (int)$_GET['society_id'];
        }
        $facilities = $this->amenityService->getFacilities($societyId);
        $this->success($facilities);
    }

    public function show(string $id): void {
        $facility = $this->amenityService->getFacility($id);
        if (!$facility) {
            $this->error('Amenity not found', 404);
            return;
        }
        $this->success($facility);
    }

    public function create(): void {
        $input = $this->getJsonInput();
        try {
            $facility = $this->amenityService->createFacility($input);
            $this->success($facility, 'Amenity created successfully', 201);
        } catch (Exception $e) {
            $this->error($e->getMessage(), 400);
        }
    }

    public function update(string $id): void {
        $input = $this->getJsonInput();
        try {
            $facility = $this->amenityService->updateFacility($id, $input);
            $this->success($facility, 'Amenity updated successfully');
        } catch (Exception $e) {
            $this->error($e->getMessage(), 400);
        }
    }

    public function delete(string $id): void {
        try {
            $success = $this->amenityService->deleteFacility($id);
            if ($success) {
                $this->success(null, 'Amenity deleted successfully');
            } else {
                $this->error('Failed to delete amenity or already deleted', 404);
            }
        } catch (Exception $e) {
            $this->error($e->getMessage(), 400);
        }
    }

    public function book(): void {
        $input = $this->getJsonInput();
        if (empty($input['amenityId']) || empty($input['booking'])) {
            $this->error('Missing required booking parameters: amenityId and booking details', 400);
            return;
        }

        try {
            $bookingCode = $this->amenityService->bookFacility($input['amenityId'], $input['booking']);
            $this->success([
                'bookingCode' => $bookingCode,
                'status' => 'Confirmed'
            ], 'Amenity booked successfully', 201);
        } catch (Exception $e) {
            $this->error($e->getMessage(), 400);
        }
    }
}
