<?php
namespace App\Services;

use App\Models\Unit;
use App\Models\Tower;
use App\Config\TenantContext;
use App\Config\Database;
use Exception;
use InvalidArgumentException;

class UnitService {
    private Unit $unitModel;
    private Tower $towerModel;

    public function __construct(?Unit $unitModel = null, ?Tower $towerModel = null) {
        $this->unitModel = $unitModel ?: new Unit();
        $this->towerModel = $towerModel ?: new Tower();
    }

    public function getFlats(array $filters = []): array {
        return $this->unitModel->getUnits($filters);
    }

    public function getUnitPassport(string $unitCode): ?array {
        return $this->unitModel->findByCode($unitCode);
    }

    public function getTelemetryCounts(): array {
        return $this->unitModel->countByStatus();
    }

    public function createUnit(array $data): array {
        $unitCode = strtoupper(trim($data['unit_code'] ?? ($data['flatNumber'] ?? '')));
        if (empty($unitCode)) {
            throw new InvalidArgumentException("Unit number / code is required.");
        }

        $towerId = (int)($data['tower_id'] ?? ($data['towerId'] ?? 0));
        $societyId = !empty($data['society_id']) ? (int)$data['society_id'] : 0;
        if ($towerId > 0 && $societyId <= 0) {
            $towerObj = $this->towerModel->findById($towerId);
            if ($towerObj && !empty($towerObj['society_id'])) {
                $societyId = (int)$towerObj['society_id'];
            }
        }
        if ($societyId <= 0) {
            $societyId = TenantContext::resolve();
        }
        if ($towerId <= 0) {
            $towerName = trim($data['tower'] ?? ($data['tower_name'] ?? ''));
            if (!empty($towerName)) {
                $resolvedTower = $this->towerModel->findByName($towerName, $societyId);
                if (!$resolvedTower) {
                    $socTowers = $this->towerModel->getBySocietyId($societyId);
                    foreach ($socTowers as $st) {
                        if (stripos($st['name'], $towerName) !== false || stripos($towerName, $st['name']) !== false) {
                            $resolvedTower = $st;
                            break;
                        }
                    }
                }
                if ($resolvedTower) {
                    $towerId = (int)$resolvedTower['id'];
                }
            }
        }
        if ($towerId <= 0) {
            $socTowers = $this->towerModel->getBySocietyId($societyId);
            $towerId = !empty($socTowers) ? (int)$socTowers[0]['id'] : 1;
        }

        $floorNumber = (int)($data['floor_number'] ?? ($data['floor'] ?? 1));
        $unitType = $data['unit_type'] ?? ($data['type'] ?? '2BHK');
        $sqftArea = (float)($data['sqft_area'] ?? ($data['area'] ?? ($data['area_sqft'] ?? 1200)));
        $occupancyStatus = $data['occupancy_status'] ?? ($data['status'] ?? 'Vacant');

        $db = Database::getConnection();
        $manageTx = !$db->inTransaction();
        if ($manageTx) {
            $db->beginTransaction();
        }

        try {
            $id = $this->unitModel->create([
                'society_id' => $societyId,
                'tower_id' => $towerId,
                'unit_code' => $unitCode,
                'floor_number' => $floorNumber,
                'unit_type' => $unitType,
                'sqft_area' => $sqftArea,
                'occupancy_status' => $occupancyStatus,
                'maintenance_status' => 'Paid',
                'owner_name' => $data['owner_name'] ?? ($data['ownerName'] ?? null),
                'contact_phone' => $data['contact_phone'] ?? ($data['phone'] ?? null),
                'contact_email' => $data['contact_email'] ?? ($data['email'] ?? null)
            ]);

            // Sync total_units counts on tower and society
            if ($towerId > 0) {
                $db->prepare("UPDATE towers SET total_units = (SELECT COUNT(*) FROM units WHERE tower_id = ? AND is_deleted = 0) WHERE id = ?")->execute([$towerId, $towerId]);
            }
            if ($societyId > 0) {
                $db->prepare("UPDATE societies SET total_units = (SELECT COUNT(*) FROM units WHERE society_id = ? AND is_deleted = 0) WHERE id = ?")->execute([$societyId, $societyId]);
            }

            if ($manageTx) {
                $db->commit();
            }

            return [
                'id' => "FLAT-{$unitCode}",
                'unitDbId' => $id,
                'flatNumber' => $unitCode,
                'floor' => $floorNumber,
                'type' => $unitType,
                'unit_type' => $unitType,
                'sqft_area' => $sqftArea,
                'area' => $sqftArea,
                'status' => $occupancyStatus
            ];
        } catch (\Throwable $e) {
            if ($manageTx && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public function updateUnit($idOrCode, array $data): array {
        $societyId = TenantContext::resolve();
        $unit = null;

        // Try lookup by numeric ID first
        if (is_numeric($idOrCode)) {
            $unit = $this->unitModel->findById((int)$idOrCode, $societyId);
        }

        // Otherwise lookup by code / clean code
        if (!$unit) {
            $cleanCode = preg_replace('/^FLAT-/i', '', (string)$idOrCode);
            $unit = $this->unitModel->findByCode($cleanCode, $societyId);
            if (!$unit) {
                $unit = $this->unitModel->findByCode((string)$idOrCode, $societyId);
            }
        }

        if (!$unit) {
            throw new InvalidArgumentException("Unit '{$idOrCode}' not found.");
        }

        $unitId = (int)$unit['id'];

        $db = Database::getConnection();
        $manageTx = !$db->inTransaction();
        if ($manageTx) {
            $db->beginTransaction();
        }

        try {
            $this->unitModel->updateUnit($unitId, $data, $societyId);

            if ($manageTx) {
                $db->commit();
            }

            $refreshed = $this->unitModel->findById($unitId, $societyId);
            return [
                'id' => "FLAT-{$refreshed['unit_code']}",
                'unitDbId' => $unitId,
                'flatNumber' => $refreshed['unit_code'],
                'tower' => $refreshed['tower_name'] ?? $unit['tower_name'],
                'floor' => (int)$refreshed['floor_number'],
                'type' => $refreshed['unit_type'],
                'unit_type' => $refreshed['unit_type'],
                'sqft_area' => (float)($refreshed['sqft_area'] ?? 1200),
                'area' => (float)($refreshed['sqft_area'] ?? 1200),
                'status' => $refreshed['occupancy_status']
            ];
        } catch (\Throwable $e) {
            if ($manageTx && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public function bulkGenerateUnits(array $params): array {
        $towerId = (int)($params['tower_id'] ?? ($params['towerId'] ?? 1));
        $startFloor = isset($params['start_floor']) ? max(0, (int)$params['start_floor']) : (isset($params['startFloor']) ? max(0, (int)$params['startFloor']) : 1);
        $endFloor = max($startFloor, (int)($params['end_floor'] ?? ($params['endFloor'] ?? 10)));
        $unitsPerFloor = max(1, min(20, (int)($params['units_per_floor'] ?? ($params['unitsPerFloor'] ?? 4))));
        $unitType = $params['unit_type'] ?? ($params['unitType'] ?? '2BHK');
        $sqftArea = (int)($params['sqft_area'] ?? ($params['sqftArea'] ?? 1050));
        $occupancyStatus = $params['occupancy_status'] ?? ($params['occupancyStatus'] ?? 'Vacant');
        $prefix = trim($params['prefix'] ?? '');

        // Validate and resolve tower belonging to current society
        $targetSocietyId = (int)($params['society_id'] ?? TenantContext::getSocietyId());
        $tower = $this->towerModel->findById($towerId);
        if (!$tower || ($targetSocietyId > 0 && (int)$tower['society_id'] !== (int)$targetSocietyId)) {
            $socTowers = $this->towerModel->getBySocietyId($targetSocietyId);
            if (!empty($socTowers)) {
                $towerId = (int)$socTowers[0]['id'];
                $tower = $socTowers[0];
            }
        }

        if (empty($prefix) && $tower) {
            if (!empty($tower['tower_code'])) {
                $prefix = strtoupper(trim($tower['tower_code']));
            } else {
                $tName = trim($tower['name'] ?? '');
                $cleaned = preg_replace('/^(Tower|Wing|Block)\s+/i', '', $tName);
                $prefix = strtoupper(!empty($cleaned) ? $cleaned : $tName);
            }
        }
        if (empty($prefix)) {
            $prefix = 'A';
        }

        $unitsToCreate = [];
        for ($floor = $startFloor; $floor <= $endFloor; $floor++) {
            $floorStr = ($floor === 0) ? 'G' : (string)$floor;
            for ($unitIdx = 1; $unitIdx <= $unitsPerFloor; $unitIdx++) {
                $unitCode = sprintf("%s-%s%02d", $prefix, $floorStr, $unitIdx);
                $unitsToCreate[] = [
                    'tower_id' => $towerId,
                    'unit_code' => $unitCode,
                    'floor_number' => $floor,
                    'unit_type' => $unitType,
                    'sqft_area' => $sqftArea,
                    'occupancy_status' => $occupancyStatus
                ];
            }
        }

        $createdCount = $this->unitModel->bulkCreate($unitsToCreate);
        return [
            'success' => true,
            'units_created' => $createdCount,
            'total_generated' => count($unitsToCreate),
            'tower_name' => $tower['name'] ?? "Tower #{$towerId}"
        ];
    }

    public function batchCreateUnits(array $params): array {
        $towerId = (int)($params['tower_id'] ?? ($params['towerId'] ?? 1));
        $units = $params['units'] ?? [];
        if (empty($units)) {
            throw new InvalidArgumentException("Units list cannot be empty.");
        }

        $unitsToCreate = [];
        foreach ($units as $u) {
            $unitCode = strtoupper(trim($u['unit_code'] ?? ($u['flatNumber'] ?? '')));
            if (empty($unitCode)) continue;
            $unitsToCreate[] = [
                'tower_id' => (int)($u['tower_id'] ?? $towerId),
                'unit_code' => $unitCode,
                'floor_number' => (int)($u['floor_number'] ?? ($u['floor'] ?? 1)),
                'unit_type' => $u['unit_type'] ?? ($u['type'] ?? '2BHK'),
                'sqft_area' => (int)($u['sqft_area'] ?? ($u['area'] ?? 1000)),
                'occupancy_status' => $u['occupancy_status'] ?? ($u['status'] ?? 'Vacant')
            ];
        }

        if (empty($unitsToCreate)) {
            throw new InvalidArgumentException("No valid units found in payload.");
        }

        $createdCount = $this->unitModel->bulkCreate($unitsToCreate);
        return [
            'success' => true,
            'units_created' => $createdCount,
            'total_generated' => count($unitsToCreate)
        ];
    }
}
