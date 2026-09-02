<?php
namespace App\Services;

use App\Models\Amenity;
use App\Config\Database;
use App\Config\TenantContext;
use InvalidArgumentException;
use Exception;

class AmenityService {
    private Amenity $amenityModel;

    public function __construct(?Amenity $amenityModel = null) {
        $this->amenityModel = $amenityModel ?: new Amenity();
    }

    public function getFacilities(?int $societyId = null): array {
        return $this->amenityModel->getAll($societyId);
    }

    public function getFacility(string $id): ?array {
        return $this->amenityModel->findById($id);
    }

    public function createFacility(array $input): array {
        if (empty($input['name'])) {
            throw new InvalidArgumentException("Amenity Name is required.");
        }

        $db = Database::getConnection();
        $manageTx = !$db->inTransaction();
        if ($manageTx) {
            $db->beginTransaction();
        }

        try {
            $societyId = !empty($input['society_id']) ? (int)$input['society_id'] : (!empty($input['societyId']) ? (int)$input['societyId'] : TenantContext::getSocietyId());
            $code = $input['id'] ?? ($input['amenity_code'] ?? ('AMN-' . strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $input['name']), 0, 4)) . '-' . rand(10, 99)));

            $data = [
                'amenity_code' => $code,
                'society_id' => $societyId,
                'name' => trim($input['name']),
                'category' => $input['category'] ?? 'Clubhouse',
                'hourly_rate' => (float)($input['hourly_rate'] ?? ($input['hourlyRate'] ?? 0)),
                'capacity' => (int)($input['capacity'] ?? 20),
                'current_occupancy' => (int)($input['current_occupancy'] ?? ($input['currentOccupancy'] ?? 0)),
                'operating_hours' => $input['operating_hours'] ?? ($input['operatingHours'] ?? '06:00 AM - 10:00 PM'),
                'status' => $input['status'] ?? 'Available',
                'location' => $input['location'] ?? 'Clubhouse',
                'description' => $input['description'] ?? '',
                'rules' => $input['rules'] ?? '',
                'image_url' => $input['image_url'] ?? ($input['image'] ?? ''),
                'media' => $input['media'] ?? []
            ];

            $this->amenityModel->create($data, $societyId);

            if ($manageTx) {
                $db->commit();
            }

            return $this->amenityModel->findById($code) ?: array_merge($data, ['id' => $code]);
        } catch (\Throwable $e) {
            if ($manageTx && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public function updateFacility(string $id, array $input): array {
        if (empty($input['name'])) {
            throw new InvalidArgumentException("Amenity Name is required.");
        }

        $db = Database::getConnection();
        $manageTx = !$db->inTransaction();
        if ($manageTx) {
            $db->beginTransaction();
        }

        try {
            $existing = $this->amenityModel->findById($id);
            if (!$existing) {
                throw new Exception("Amenity not found or deleted.");
            }

            $this->amenityModel->update($id, $input);

            if ($manageTx) {
                $db->commit();
            }

            return $this->amenityModel->findById($id) ?: array_merge($existing, $input);
        } catch (\Throwable $e) {
            if ($manageTx && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public function deleteFacility(string $id): bool {
        $db = Database::getConnection();
        $manageTx = !$db->inTransaction();
        if ($manageTx) {
            $db->beginTransaction();
        }

        try {
            $success = $this->amenityModel->delete($id);

            if ($manageTx) {
                $db->commit();
            }
            return $success;
        } catch (\Throwable $e) {
            if ($manageTx && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public function bookFacility(string $amenityCode, array $bookingData): string {
        $db = Database::getConnection();
        $manageTx = !$db->inTransaction();
        if ($manageTx) {
            $db->beginTransaction();
        }

        try {
            $code = $this->amenityModel->bookSlot($amenityCode, $bookingData);

            if ($manageTx) {
                $db->commit();
            }
            return $code;
        } catch (\Throwable $e) {
            if ($manageTx && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }
}
