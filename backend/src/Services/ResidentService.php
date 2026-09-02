<?php
namespace App\Services;

use App\Models\Resident;
use App\Models\FamilyMember;
use App\Models\ResidentDocument;
use App\Models\Vehicle;
use App\Models\Unit;
use App\Models\User;
use App\Models\Role;
use App\Config\Database;
use App\Config\TenantContext;
use Exception;
use InvalidArgumentException;

class ResidentService {
    private Resident $residentModel;
    private FamilyMember $familyMemberModel;
    private ResidentDocument $documentModel;
    private Vehicle $vehicleModel;
    private Unit $unitModel;
    private User $userModel;
    private Role $roleModel;

    public function __construct(
        ?Resident $residentModel = null,
        ?FamilyMember $familyMemberModel = null,
        ?ResidentDocument $documentModel = null,
        ?Vehicle $vehicleModel = null,
        ?Unit $unitModel = null,
        ?User $userModel = null,
        ?Role $roleModel = null
    ) {
        $this->residentModel = $residentModel ?: new Resident();
        $this->familyMemberModel = $familyMemberModel ?: new FamilyMember();
        $this->documentModel = $documentModel ?: new ResidentDocument();
        $this->vehicleModel = $vehicleModel ?: new Vehicle();
        $this->unitModel = $unitModel ?: new Unit();
        $this->userModel = $userModel ?: new User();
        $this->roleModel = $roleModel ?: new Role();
    }

    public function getResidents(array $filters = []): array {
        return $this->residentModel->getAll(null, $filters);
    }

    public function getResidentPassport(int $id): ?array {
        return $this->residentModel->findById($id);
    }

    public function getResidentById(int $id): ?array {
        return $this->residentModel->findById($id);
    }

    public function onboard(array $input): array {
        $name = trim($input['name'] ?? ($input['full_name'] ?? ($input['fullName'] ?? '')));
        $flatNumber = trim($input['flat'] ?? ($input['flatNumber'] ?? ($input['flat_number'] ?? ($input['unit_code'] ?? ($input['unitCode'] ?? '')))));

        if (empty($name) || empty($flatNumber)) {
            throw new InvalidArgumentException("Full Name and Flat / Room Code are required.");
        }

        $societyId = TenantContext::getSocietyId();
        $role = $input['role'] ?? ($input['resident_type'] ?? 'Owner');
        $residentType = (strcasecmp($role, 'Tenant') === 0) ? 'Tenant' : 'Owner';
        $ownerName = !empty($input['owner_name']) ? trim($input['owner_name']) : (!empty($input['ownerName']) ? trim($input['ownerName']) : $name);
        $phone = $input['phone'] ?? '+91 98200-11223';
        $email = $input['email'] ?? (strtolower(preg_replace('/[^a-z0-9]/', '.', $name)) . '@emerald.net');
        $moveInDate = !empty($input['moveInDate']) ? $input['moveInDate'] : (!empty($input['move_in_date']) ? $input['move_in_date'] : date('Y-m-d'));
        if (strtotime($moveInDate) === false) {
            $moveInDate = date('Y-m-d');
        }

        $db = Database::getConnection();
        $manageTx = !$db->inTransaction();
        if ($manageTx) {
            $db->beginTransaction();
        }

        try {
            // 1. Locate unit
            $unit = $this->unitModel->findByCode($flatNumber, $societyId);
            if (!$unit && !empty($input['tower'])) {
                // Try with tower prefix e.g. "Cedar-C-106"
                $unit = $this->unitModel->findByCode(trim($input['tower']) . '-' . $flatNumber, $societyId);
            }
            if (!$unit) {
                // Try finding across all units
                $unit = $this->unitModel->findByCode($flatNumber, null);
            }

            // If unit does not exist in DB yet, auto-provision it gracefully under tower
            if (!$unit) {
                $towerName = !empty($input['tower']) ? trim($input['tower']) : 'Tower A';
                $towerStmt = $db->prepare("SELECT id FROM towers WHERE (society_id = ? OR society_id IS NULL) AND (name = ? OR tower_code = ?) AND is_deleted = 0 LIMIT 1");
                $towerStmt->execute([$societyId, $towerName, $towerName]);
                $tower = $towerStmt->fetch();
                $towerId = $tower ? (int)$tower['id'] : null;

                if (!$towerId) {
                    $towerCode = strtoupper(substr($towerName, 0, 3));
                    $towerCreateStmt = $db->prepare("INSERT INTO towers (society_id, name, tower_code, total_floors, total_units) VALUES (?, ?, ?, 10, 40)");
                    $towerCreateStmt->execute([$societyId, $towerName, $towerCode]);
                    $towerId = (int)$db->lastInsertId();
                }

                $floor = 1;
                if (preg_match('/(\d+)/', $flatNumber, $m)) {
                    $num = (int)$m[1];
                    $floor = $num >= 100 ? (int)floor($num / 100) : 1;
                }

                $newUnitId = $this->unitModel->create([
                    'society_id' => $societyId,
                    'tower_id' => $towerId,
                    'unit_code' => $flatNumber,
                    'floor_number' => $floor,
                    'unit_type' => '2BHK',
                    'sqft_area' => 950,
                    'occupancy_status' => 'Vacant',
                    'maintenance_status' => 'Current'
                ], $societyId);

                $unit = $this->unitModel->findById($newUnitId, $societyId);
            }

            if (!$unit) {
                throw new Exception("Unit {$flatNumber} could not be resolved in society.");
            }

            $unitId = (int)$unit['id'];
            $canonicalFlatNumber = $unit['unit_code'];

            // 2. Find or create user account
            $user = $this->userModel->findByEmail($email, $societyId);
            $userId = null;

            $initialPassword = !empty($input['password']) ? trim($input['password']) : 'Welcome@123';
            $isNewUserCreated = false;

            if ($user) {
                $userId = (int)$user['id'];
                if (!empty($input['password'])) {
                    $this->userModel->update($userId, [
                        'password_hash' => password_hash($initialPassword, PASSWORD_BCRYPT)
                    ], $societyId);
                }
            } else {
                // Determine resident role id
                $residentRole = $this->roleModel->findByCode('resident');
                $roleId = $residentRole ? (int)$residentRole['id'] : 7;

                $userCode = 'USR-' . rand(3000, 9999);
                $userId = $this->userModel->create([
                    'user_code' => $userCode,
                    'society_id' => $societyId,
                    'is_parent_user' => 0,
                    'full_name' => $name,
                    'email' => $email,
                    'password_hash' => password_hash($initialPassword, PASSWORD_BCRYPT),
                    'role_id' => $roleId,
                    'phone' => $phone,
                    'unit_code' => $canonicalFlatNumber,
                    'status' => 'Active',
                    'avatar_url' => $input['avatar_url'] ?? 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=150&auto=format&fit=crop&q=80'
                ], $societyId);
                $isNewUserCreated = true;
            }

            // 3. Update unit occupancy status and names
            $occupancyStatus = ($residentType === 'Tenant') ? 'Occupied (Tenant)' : 'Occupied (Owner)';
            $finalOwner = ($residentType === 'Tenant') ? $ownerName : $name;
            $finalTenant = ($residentType === 'Tenant') ? $name : null;

            $this->unitModel->updateOccupancy($unitId, [
                'occupancy_status' => $occupancyStatus,
                'owner_name' => $finalOwner,
                'tenant_name' => $finalTenant,
                'contact_phone' => $phone,
                'contact_email' => $email
            ], $societyId);

            // 4. Create resident record
            $verificationStatus = $input['verification_status'] ?? ($input['verificationStatus'] ?? 'Approved');
            $residentId = $this->residentModel->create([
                'society_id' => $societyId,
                'user_id' => $userId,
                'unit_id' => $unitId,
                'resident_type' => $residentType,
                'is_primary_contact' => isset($input['is_primary_contact']) ? (int)$input['is_primary_contact'] : 1,
                'move_in_date' => $moveInDate,
                'move_out_date' => $input['move_out_date'] ?? null,
                'verification_status' => $verificationStatus,
                'verified_by_user_id' => $input['verified_by_user_id'] ?? null,
                'rejection_reason' => $input['rejection_reason'] ?? null
            ], $societyId);

            // Update user with resident_id link
            $this->userModel->update($userId, [
                'resident_id' => $residentId,
                'unit_code' => $canonicalFlatNumber
            ], $societyId);

            // 5. Link occupancy
            $this->residentModel->linkOccupancy($unitId, $residentId, $residentType);

            // 6. Insert Family Members
            $familyMembersInput = $input['family_members'] ?? ($input['familyMembers'] ?? []);
            if (is_array($familyMembersInput)) {
                foreach ($familyMembersInput as $fm) {
                    if (!empty($fm['full_name']) || !empty($fm['name'])) {
                        $this->familyMemberModel->create([
                            'resident_id' => $residentId,
                            'full_name' => trim($fm['full_name'] ?? $fm['name']),
                            'relation' => $fm['relation'] ?? 'Other',
                            'phone' => $fm['phone'] ?? null,
                            'age' => isset($fm['age']) && $fm['age'] !== '' ? (int)$fm['age'] : null,
                            'gender' => $fm['gender'] ?? 'Other',
                            'photo_url' => $fm['photo_url'] ?? null
                        ]);
                    }
                }
            }

            // 7. Insert Resident Documents
            $documentsInput = $input['documents'] ?? ($input['resident_documents'] ?? []);
            if (is_array($documentsInput)) {
                foreach ($documentsInput as $doc) {
                    $docType = $doc['document_type'] ?? ($doc['type'] ?? 'ID Proof');
                    $docNumber = $doc['document_number'] ?? ($doc['number'] ?? null);
                    $fileUrl = $doc['file_url'] ?? ($doc['url'] ?? (!empty($doc['file']) && is_string($doc['file']) ? $doc['file'] : null));

                    if (!empty($fileUrl) || !empty($docNumber) || !empty($docType)) {
                        $this->documentModel->create([
                            'resident_id' => $residentId,
                            'document_type' => $docType,
                            'file_name' => $doc['file_name'] ?? ($docType . ($docNumber ? " ({$docNumber})" : "")),
                            'file_url' => $fileUrl ?: 'https://images.unsplash.com/photo-1568602471122-7832951cc4c5?w=500&auto=format&fit=crop&q=80'
                        ]);
                    }
                }
            }

            // 8. Insert Vehicles
            $vehiclesInput = $input['vehicles'] ?? [];
            if (is_array($vehiclesInput) && count($vehiclesInput) > 0) {
                foreach ($vehiclesInput as $v) {
                    $vehNum = is_string($v) ? $v : ($v['plateNumber'] ?? ($v['vehicle_number'] ?? ($v['tag'] ?? ($v['vehicleNumber'] ?? ($v['number'] ?? '')))));
                    if (!empty($vehNum)) {
                        $this->vehicleModel->create([
                            'society_id' => $societyId,
                            'unit_id' => $unitId,
                            'resident_id' => $residentId,
                            'vehicle_number' => trim($vehNum),
                            'vehicle_type' => is_array($v) ? ($v['vehicle_type'] ?? ($v['type'] ?? '4-Wheeler (Car)')) : '4-Wheeler (Car)',
                            'make_model' => is_array($v) ? ($v['make_model'] ?? ($v['model'] ?? null)) : null,
                            'parking_slot_number' => is_array($v) ? ($v['parking_slot_number'] ?? ($v['slot'] ?? null)) : null,
                            'rfid_sticker_tag' => is_array($v) ? ($v['rfid_sticker_tag'] ?? ($v['rfid'] ?? null)) : null,
                            'pass_status' => 'Valid'
                        ], $societyId);
                    }
                }
            } elseif (!empty($input['vehicle'])) {
                // Single vehicle fallback
                $this->vehicleModel->create([
                    'society_id' => $societyId,
                    'unit_id' => $unitId,
                    'resident_id' => $residentId,
                    'vehicle_number' => trim($input['vehicle']),
                    'vehicle_type' => '4-Wheeler (Car)',
                    'pass_status' => 'Valid'
                ], $societyId);
            }

            if ($manageTx) {
                $db->commit();
            }

            $passport = $this->residentModel->findById($residentId, $societyId) ?: [
                'id' => "RES-" . str_pad($residentId, 4, '0', STR_PAD_LEFT),
                'resident_id' => $residentId,
                'flatNumber' => $flatNumber,
                'name' => $name,
                'role' => $residentType,
                'phone' => $phone,
                'email' => $email,
                'status' => 'Paid'
            ];

            $passport['credentials'] = [
                'userId' => $userId,
                'userCode' => $user ? ($user['user_code'] ?? 'USR-' . $userId) : ($userCode ?? 'USR-' . $userId),
                'username' => $email,
                'email' => $email,
                'phone' => $phone,
                'initialPassword' => $initialPassword,
                'role' => $residentType,
                'roleName' => "Resident ({$residentType})",
                'status' => 'Active',
                'accountCreated' => $isNewUserCreated,
                'loginUrl' => '/login'
            ];

            return $passport;
        } catch (\Throwable $e) {
            if ($manageTx && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public function getOrResetCredentials(int $residentId, ?string $customPassword = null): array {
        $resident = $this->residentModel->findById($residentId);
        if (!$resident) {
            throw new Exception("Resident not found.");
        }

        $societyId = (int)($resident['society_id'] ?? TenantContext::getSocietyId());
        $userId = $resident['user_id'] ?? null;
        $email = $resident['email'] ?: (strtolower(preg_replace('/[^a-z0-9]/', '.', $resident['name'])) . '@emerald.net');
        $phone = $resident['phone'] ?: '+91 98200-11223';
        $newPassword = !empty($customPassword) ? trim($customPassword) : 'Welcome@123';

        $db = Database::getConnection();
        $manageTx = !$db->inTransaction();
        if ($manageTx) {
            $db->beginTransaction();
        }

        try {
            $userCode = null;
            if ($userId) {
                $user = $this->userModel->findById((int)$userId, 0) ?: $this->userModel->findById((int)$userId);
                $userCode = $user['user_code'] ?? ('USR-' . $userId);
                $this->userModel->update((int)$userId, [
                    'password_hash' => password_hash($newPassword, PASSWORD_BCRYPT),
                    'status' => 'Active'
                ], $societyId);
            } else {
                $residentRole = $this->roleModel->findByCode('resident');
                $roleId = $residentRole ? (int)$residentRole['id'] : 7;
                $userCode = 'USR-' . rand(3000, 9999);

                $userId = $this->userModel->create([
                    'user_code' => $userCode,
                    'society_id' => $societyId,
                    'is_parent_user' => 0,
                    'full_name' => $resident['name'],
                    'email' => $email,
                    'password_hash' => password_hash($newPassword, PASSWORD_BCRYPT),
                    'role_id' => $roleId,
                    'phone' => $phone,
                    'unit_code' => $resident['flatNumber'] ?? '',
                    'resident_id' => $residentId,
                    'status' => 'Active'
                ], $societyId);

                $this->residentModel->update($residentId, ['user_id' => $userId], $societyId);
            }

            if ($manageTx) {
                $db->commit();
            }

            return [
                'residentId' => $residentId,
                'userId' => (int)$userId,
                'userCode' => $userCode,
                'fullName' => $resident['name'],
                'flatNumber' => $resident['flatNumber'],
                'role' => $resident['role'],
                'username' => $email,
                'email' => $email,
                'phone' => $phone,
                'password' => $newPassword,
                'status' => 'Active',
                'loginUrl' => '/login'
            ];
        } catch (\Throwable $e) {
            if ($manageTx && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public function verifyResident(int $residentId, string $status, ?string $rejectionReason = null, ?int $verifiedByUserId = null): bool {
        $allowed = ['Pending', 'Approved', 'Rejected'];
        if (!in_array($status, $allowed)) {
            throw new InvalidArgumentException("Status must be Pending, Approved, or Rejected.");
        }
        return $this->residentModel->updateVerificationStatus($residentId, $status, $verifiedByUserId, $rejectionReason);
    }

    public function addFamilyMember(int $residentId, array $data): array {
        $data['resident_id'] = $residentId;
        $id = $this->familyMemberModel->create($data);
        return $this->familyMemberModel->findById($id);
    }

    public function deleteFamilyMember(int $memberId): bool {
        return $this->familyMemberModel->delete($memberId);
    }

    public function addDocument(int $residentId, array $data): array {
        $data['resident_id'] = $residentId;
        $id = $this->documentModel->create($data);
        return $this->documentModel->findById($id);
    }

    public function deleteDocument(int $docId): bool {
        return $this->documentModel->delete($docId);
    }

    public function addVehicle(int $residentId, array $data): array {
        $resident = $this->residentModel->findById($residentId);
        if (!$resident) {
            throw new Exception("Resident not found.");
        }
        $data['resident_id'] = $residentId;
        $data['unit_id'] = $resident['unit_id'];
        $data['society_id'] = $resident['society_id'];

        $id = $this->vehicleModel->create($data, $resident['society_id']);
        return $this->vehicleModel->findById($id, $resident['society_id']);
    }

    public function deleteVehicle(int $vehicleId): bool {
        return $this->vehicleModel->delete($vehicleId);
    }

    /**
     * Permanently delete a resident record and clean up all linked data.
     * Cascading FK deletes handle family_members, resident_documents, vehicles, unit_occupancies.
     * Also resets the unit occupancy status if no other residents remain.
     */
    public function deleteResident(int $residentId): bool {
        $resident = $this->residentModel->findById($residentId);
        if (!$resident) {
            throw new Exception("Resident record not found.");
        }

        $societyId = $resident['society_id'];
        $unitId = $resident['unit_id'];

        $db = Database::getConnection();
        $manageTx = !$db->inTransaction();
        if ($manageTx) {
            $db->beginTransaction();
        }

        try {
            // 1. Delete unit_occupancies for this resident
            $stmt = $db->prepare("UPDATE unit_occupancies SET is_deleted = 1, deleted_at = NOW() WHERE resident_id = ?");
            $stmt->execute([$residentId]);

            // 2. Delete resident record (CASCADE handles family, docs, vehicles)
            $this->residentModel->delete($residentId, $societyId);

            // 3. Check if any other residents remain on this unit
            $remaining = $db->prepare("SELECT id, resident_type FROM residents WHERE unit_id = ? AND society_id = ? AND is_deleted = 0");
            $remaining->execute([$unitId, $societyId]);
            $remainingResidents = $remaining->fetchAll(\PDO::FETCH_ASSOC);

            if (empty($remainingResidents)) {
                // No residents left — reset unit to Vacant
                $this->unitModel->updateOccupancy($unitId, [
                    'occupancy_status' => 'Vacant',
                    'owner_name' => null,
                    'tenant_name' => null,
                    'contact_phone' => null,
                    'contact_email' => null
                ], $societyId);
            } else {
                // Recalculate: check if any owner or tenant remains and sync details
                $remainingWithUsers = $db->prepare("
                    SELECT r.resident_type, u.full_name, u.phone, u.email 
                    FROM residents r 
                    JOIN users u ON r.user_id = u.id 
                    WHERE r.unit_id = ? AND r.society_id = ? AND r.is_deleted = 0
                ");
                $remainingWithUsers->execute([$unitId, $societyId]);
                $allResidents = $remainingWithUsers->fetchAll(\PDO::FETCH_ASSOC);

                $ownerName = null;
                $tenantName = null;
                $contactPhone = null;
                $contactEmail = null;
                $hasOwner = false;
                $hasTenant = false;

                foreach ($allResidents as $r) {
                    if ($r['resident_type'] === 'Owner') {
                        $hasOwner = true;
                        if (!$ownerName) {
                            $ownerName = $r['full_name'];
                            $contactPhone = $r['phone'];
                            $contactEmail = $r['email'];
                        }
                    }
                    if ($r['resident_type'] === 'Tenant') {
                        $hasTenant = true;
                        if (!$tenantName) {
                            $tenantName = $r['full_name'];
                        }
                    }
                }

                $newStatus = $hasTenant ? 'Occupied (Tenant)' : ($hasOwner ? 'Occupied (Owner)' : 'Vacant');
                $this->unitModel->updateOccupancy($unitId, [
                    'occupancy_status' => $newStatus,
                    'owner_name' => $ownerName,
                    'tenant_name' => $tenantName,
                    'contact_phone' => $contactPhone,
                    'contact_email' => $contactEmail
                ], $societyId);
            }

            if ($manageTx) {
                $db->commit();
            }
            return true;
        } catch (\Throwable $e) {
            if ($manageTx && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Change a resident's type between Owner and Tenant.
     */
    public function updateResidentType(int $residentId, string $newType): array {
        $allowed = ['Owner', 'Tenant'];
        if (!in_array($newType, $allowed)) {
            throw new InvalidArgumentException("Resident type must be 'Owner' or 'Tenant'.");
        }

        $resident = $this->residentModel->findById($residentId);
        if (!$resident) {
            throw new Exception("Resident record not found.");
        }

        $societyId = $resident['society_id'];
        $unitId = $resident['unit_id'];

        $db = Database::getConnection();
        $manageTx = !$db->inTransaction();
        if ($manageTx) {
            $db->beginTransaction();
        }

        try {
            // 1. Update resident_type
            $stmt = $db->prepare("UPDATE residents SET resident_type = ? WHERE id = ? AND society_id = ?");
            $stmt->execute([$newType, $residentId, $societyId]);

            // 2. Update unit_occupancies
            $stmt2 = $db->prepare("UPDATE unit_occupancies SET occupancy_type = ? WHERE resident_id = ?");
            $stmt2->execute([$newType, $residentId]);

            // 3. Recalculate unit occupancy status and sync names
            $remaining = $db->prepare("
                SELECT r.resident_type, u.full_name, u.phone, u.email 
                FROM residents r 
                JOIN users u ON r.user_id = u.id 
                WHERE r.unit_id = ? AND r.society_id = ? AND r.is_deleted = 0
            ");
            $remaining->execute([$unitId, $societyId]);
            $allResidents = $remaining->fetchAll(\PDO::FETCH_ASSOC);

            $ownerName = null;
            $tenantName = null;
            $contactPhone = null;
            $contactEmail = null;
            $hasOwner = false;
            $hasTenant = false;

            foreach ($allResidents as $r) {
                if ($r['resident_type'] === 'Owner') {
                    $hasOwner = true;
                    if (!$ownerName) {
                        $ownerName = $r['full_name'];
                        $contactPhone = $r['phone'];
                        $contactEmail = $r['email'];
                    }
                }
                if ($r['resident_type'] === 'Tenant') {
                    $hasTenant = true;
                    if (!$tenantName) {
                        $tenantName = $r['full_name'];
                    }
                }
            }

            $newStatus = $hasTenant ? 'Occupied (Tenant)' : ($hasOwner ? 'Occupied (Owner)' : 'Vacant');
            $this->unitModel->updateOccupancy($unitId, [
                'occupancy_status' => $newStatus,
                'owner_name' => $ownerName,
                'tenant_name' => $tenantName,
                'contact_phone' => $contactPhone,
                'contact_email' => $contactEmail
            ], $societyId);

            if ($manageTx) {
                $db->commit();
            }

            return $this->residentModel->findById($residentId, $societyId) ?: ['id' => $residentId, 'resident_type' => $newType];
        } catch (\Throwable $e) {
            if ($manageTx && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }
}