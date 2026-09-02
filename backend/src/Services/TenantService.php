<?php
namespace App\Services;

use App\Models\Society;
use App\Config\Database;
use Exception;
use InvalidArgumentException;

class TenantService {
    private Society $societyModel;

    public function __construct(?Society $societyModel = null) {
        $this->societyModel = $societyModel ?: new Society();
    }

    public function getActiveSociety(int $societyId): ?array {
        return $this->societyModel->findById($societyId);
    }

    public function getAllSocieties(): array {
        $currentUser = \App\Config\RbacGuard::getCurrentUser();
        $isParentUser = !empty($currentUser['isParentUser']);
        $societyId = \App\Config\TenantContext::getSocietyId();

        if ($isParentUser || $currentUser === null) {
            return $this->societyModel->getAll();
        } else {
            // When a client society user is logged in, strictly return only their single society
            $targetId = (int)(!empty($currentUser['societyId']) ? $currentUser['societyId'] : $societyId);
            $soc = $this->societyModel->findById($targetId);
            return $soc ? [$soc] : [];
        }
    }

    public function createSociety(array $input): array {
        $name = trim($input['name'] ?? '');
        $code = strtoupper(trim($input['society_code'] ?? ($input['societyCode'] ?? '')));

        if (empty($name) || empty($code)) {
            throw new InvalidArgumentException("Society Name and Code are required.");
        }

        $db = Database::getConnection();
        $manageTx = !$db->inTransaction();
        if ($manageTx) {
            $db->beginTransaction();
        }

        try {
            $id = $this->societyModel->create([
                'society_code' => $code,
                'name' => $name,
                'registration_number' => $input['registration_number'] ?? ($input['registrationNumber'] ?? null),
                'address_line1' => $input['address_line1'] ?? ($input['addressLine1'] ?? ($input['address'] ?? null)),
                'address_line2' => $input['address_line2'] ?? ($input['addressLine2'] ?? null),
                'address' => $input['address'] ?? ($input['address_line1'] ?? null),
                'city' => $input['city'] ?? null,
                'state' => $input['state'] ?? null,
                'pincode' => $input['pincode'] ?? null,
                'country' => $input['country'] ?? 'India',
                'zone_id' => isset($input['zone_id']) ? (int)$input['zone_id'] : (isset($input['zoneId']) ? (int)$input['zoneId'] : null),
                'zone' => $input['zone'] ?? null,
                'contact_email' => $input['contact_email'] ?? ($input['contactEmail'] ?? null),
                'contact_phone' => $input['contact_phone'] ?? ($input['contactPhone'] ?? null),
                'logo_url' => $input['logo_url'] ?? ($input['logoUrl'] ?? null),
                'currency' => $input['currency'] ?? 'INR',
                'timezone' => $input['timezone'] ?? 'Asia/Kolkata',
                'is_active' => isset($input['is_active']) ? (int)$input['is_active'] : (isset($input['isActive']) ? (int)$input['isActive'] : 1),
                'tagline' => $input['tagline'] ?? 'Smart Connected Living',
                'total_units' => (int)($input['total_units'] ?? ($input['totalUnits'] ?? 100))
            ]);

            $society = $this->societyModel->findById($id);

            // Record in Security Audit Logs
            $currentUser = \App\Config\RbacGuard::getCurrentUser();
            $actorName = $currentUser['name'] ?? 'Master Admin';
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $auditStmt = $db->prepare("INSERT INTO `audit_logs` (`society_id`, `action`, `entity_type`, `entity_id`, `actor_name`, `ip_address`, `details`) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $auditStmt->execute([
                $id,
                'SOCIETY_CREATED',
                'societies',
                $code,
                $actorName,
                $ip,
                json_encode(['form_data' => $input, 'created_society' => $society], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ]);

            if ($manageTx) {
                $db->commit();
            }

            return $society;
        } catch (\Throwable $e) {
            if ($manageTx && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public function updateSociety(int $id, array $input): array {
        $currentUser = \App\Config\RbacGuard::getCurrentUser();
        $isParentUser = !empty($currentUser['isParentUser']);

        if (!$isParentUser && $currentUser !== null && (int)($currentUser['societyId'] ?? 0) !== $id) {
            throw new Exception("Unauthorized to edit details of another society.");
        }

        $existing = $this->societyModel->findById($id);
        if (!$existing) {
            throw new Exception("Society not found.");
        }

        $db = Database::getConnection();
        $manageTx = !$db->inTransaction();
        if ($manageTx) {
            $db->beginTransaction();
        }

        try {
            $updateData = [];
            if (!empty($input['name'])) $updateData['name'] = trim($input['name']);
            if (isset($input['tagline'])) $updateData['tagline'] = trim($input['tagline']);
            if (isset($input['registration_number']) || isset($input['registrationNumber'])) {
                $updateData['registration_number'] = trim($input['registration_number'] ?? $input['registrationNumber']);
            }
            if (isset($input['address_line1']) || isset($input['addressLine1'])) {
                $updateData['address_line1'] = trim($input['address_line1'] ?? $input['addressLine1']);
            }
            if (isset($input['address_line2']) || isset($input['addressLine2'])) {
                $updateData['address_line2'] = trim($input['address_line2'] ?? $input['addressLine2']);
            }
            if (isset($input['address'])) $updateData['address'] = trim($input['address']);
            if (isset($input['city'])) $updateData['city'] = trim($input['city']);
            if (isset($input['state'])) $updateData['state'] = trim($input['state']);
            if (isset($input['pincode'])) $updateData['pincode'] = trim($input['pincode']);
            if (isset($input['country'])) $updateData['country'] = trim($input['country']);
            if (isset($input['zone_id']) || isset($input['zoneId'])) {
                $updateData['zone_id'] = (int)($input['zone_id'] ?? $input['zoneId']);
            }
            if (isset($input['zone'])) $updateData['zone'] = trim($input['zone']);
            if (isset($input['contact_email']) || isset($input['contactEmail'])) {
                $updateData['contact_email'] = trim($input['contact_email'] ?? $input['contactEmail']);
            }
            if (isset($input['contact_phone']) || isset($input['contactPhone'])) {
                $updateData['contact_phone'] = trim($input['contact_phone'] ?? $input['contactPhone']);
            }
            if (isset($input['logo_url']) || isset($input['logoUrl'])) {
                $updateData['logo_url'] = trim($input['logo_url'] ?? $input['logoUrl']);
            }
            if (isset($input['currency'])) $updateData['currency'] = trim($input['currency']);
            if (isset($input['timezone'])) $updateData['timezone'] = trim($input['timezone']);
            if (isset($input['is_active']) || isset($input['isActive'])) {
                $updateData['is_active'] = (int)($input['is_active'] ?? $input['isActive']);
            }
            if (isset($input['total_units']) || isset($input['totalUnits'])) {
                $updateData['total_units'] = (int)($input['total_units'] ?? $input['totalUnits']);
            }
            if (!empty($input['society_code']) || !empty($input['societyCode'])) {
                $updateData['society_code'] = strtoupper(trim($input['society_code'] ?? $input['societyCode']));
            }

            $this->societyModel->update($id, $updateData);
            $updated = $this->societyModel->findById($id);

            // Record in Security Audit Logs
            $actorName = $currentUser['name'] ?? 'Master Admin';
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $auditStmt = $db->prepare("INSERT INTO `audit_logs` (`society_id`, `action`, `entity_type`, `entity_id`, `actor_name`, `ip_address`, `details`) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $auditStmt->execute([
                $id,
                'SOCIETY_UPDATED',
                'societies',
                $updated['society_code'] ?? (string)$id,
                $actorName,
                $ip,
                json_encode(['form_data' => $input, 'updated_fields' => $updateData, 'result' => $updated], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ]);

            if ($manageTx) {
                $db->commit();
            }

            return $updated;
        } catch (\Throwable $e) {
            if ($manageTx && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }
}
