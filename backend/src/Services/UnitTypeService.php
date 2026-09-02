<?php
namespace App\Services;

use App\Models\UnitType;
use App\Config\TenantContext;
use App\Config\Database;
use Exception;
use InvalidArgumentException;

class UnitTypeService {
    private UnitType $unitTypeModel;

    public function __construct(?UnitType $unitTypeModel = null) {
        $this->unitTypeModel = $unitTypeModel ?: new UnitType();
    }

    public function getUnitTypes(): array {
        $societyId = TenantContext::getSocietyId();
        $types = $this->unitTypeModel->getAll($societyId);
        
        $formatted = [];
        foreach ($types as $t) {
            $formatted[] = [
                'id' => (int)$t['id'],
                'societyId' => $t['society_id'] ? (int)$t['society_id'] : null,
                'typeName' => $t['type_name'],
                'type' => $t['type_name'],
                'badgeColor' => $t['badge_color'] ?? 'blue',
                'typicalArea' => $t['typical_area'],
                'area' => $t['typical_area'],
                'standardSqft' => (int)$t['standard_sqft'],
                'useCase' => $t['use_case'],
                'isSystemStandard' => (bool)$t['is_system_standard'],
                'createdAt' => $t['created_at'] ?? null
            ];
        }
        return $formatted;
    }

    public function createUnitType(array $input): array {
        $societyId = TenantContext::getSocietyId();
        $db = Database::getConnection();
        $manageTx = !$db->inTransaction();
        if ($manageTx) {
            $db->beginTransaction();
        }

        try {
            $id = $this->unitTypeModel->create($input, $societyId);
            $created = $this->unitTypeModel->findById($id);

            if ($manageTx) {
                $db->commit();
            }

            return [
                'id' => (int)$created['id'],
                'societyId' => (int)$created['society_id'],
                'typeName' => $created['type_name'],
                'type' => $created['type_name'],
                'badgeColor' => $created['badge_color'] ?? 'blue',
                'typicalArea' => $created['typical_area'],
                'area' => $created['typical_area'],
                'standardSqft' => (int)$created['standard_sqft'],
                'useCase' => $created['use_case'],
                'isSystemStandard' => false,
                'createdAt' => $created['created_at'] ?? null
            ];
        } catch (\Throwable $e) {
            if ($manageTx && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public function deleteUnitType(int $id): bool {
        $societyId = TenantContext::getSocietyId();
        $db = Database::getConnection();
        $manageTx = !$db->inTransaction();
        if ($manageTx) {
            $db->beginTransaction();
        }

        try {
            $res = $this->unitTypeModel->delete($id, $societyId);
            if ($manageTx) {
                $db->commit();
            }
            return $res;
        } catch (\Throwable $e) {
            if ($manageTx && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }
}