<?php
namespace App\Services;

use App\Models\Visitor;
use App\Models\Notice;
use App\Models\Unit;
use App\Models\Resident;
use App\Config\Database;
use App\Config\TenantContext;
use App\Services\PushNotificationService;
use InvalidArgumentException;
use Exception;

class VisitorService {
    private Visitor $visitorModel;
    private ?Notice $noticeModel;
    private ?Unit $unitModel;
    private ?Resident $residentModel;

    public function __construct(
        ?Visitor $visitorModel = null,
        ?Notice $noticeModel = null,
        ?Unit $unitModel = null,
        ?Resident $residentModel = null
    ) {
        $this->visitorModel = $visitorModel ?: new Visitor();
        $this->noticeModel = $noticeModel ?: new Notice();
        $this->unitModel = $unitModel ?: new Unit();
        $this->residentModel = $residentModel ?: new Resident();
    }

    public function getVisitors(): array {
        return $this->visitorModel->getAll();
    }

    public function generateUniquePassCode(int $societyId): string {
        do {
            $code = 'PASS-' . mt_rand(100000, 999999);
            $taken = $this->visitorModel->isPassCodeTaken($code, $societyId);
        } while ($taken);
        return $code;
    }

    public function issuePass(array $input): array {
        if (empty($input['name'])) {
            throw new InvalidArgumentException("Visitor name is required.");
        }

        $db = Database::getConnection();
        $manageTx = !$db->inTransaction();
        if ($manageTx) {
            $db->beginTransaction();
        }

        try {
            $societyId = (int)($input['society_id'] ?? TenantContext::getSocietyId());
            $code = $input['id'] ?? ('VIS-' . mt_rand(100000, 999999));
            $passCode = !empty($input['passCode']) ? trim($input['passCode']) : $this->generateUniquePassCode($societyId);
            $flatCode = trim($input['flatVisiting'] ?? ($input['flat_visiting'] ?? ($input['flatNumber'] ?? '')));
            $visitorName = trim($input['name']);
            $visitorType = $input['type'] ?? ($input['visitor_type'] ?? 'Guest');
            $phone = trim($input['phone'] ?? '+91 98200-00000');
            $purpose = trim($input['purpose'] ?? 'General Visit');
            $gateNumber = $input['gateNumber'] ?? ($input['gate_number'] ?? 'Gate 1 (North)');
            $vehicleNumber = $input['vehicleNumber'] ?? ($input['vehicle_number'] ?? 'Walk-in');
            $photoUrl = $input['photoUrl'] ?? ($input['photo_url'] ?? null);

            $isPreApproved = !empty($input['isPreApproved']) || 
                             !empty($input['preApproved']) || 
                             ($input['status'] ?? '') === 'Expected' || 
                             ($input['approval_status'] ?? '') === 'Approved' || 
                             ($input['approvalStatus'] ?? '') === 'Approved' ||
                             !empty($input['isOwnerPreApproved']);
            $initialApproval = $isPreApproved ? 'Approved' : 'Pending Approval';
            $initialStatus = $isPreApproved ? 'Expected' : 'Waiting at Gate';
            $initialCheckIn = $isPreApproved ? 'Expected / Pre-Approved' : ($input['checkInTime'] ?? 'Awaiting Entry');

            // 1. Resolve Unit & Occupant Notification Recipient
            $notifiedResidentName = null;
            $notifiedResidentType = null;
            $unitId = null;

            if (!empty($flatCode)) {
                // Find matching unit
                $unit = $this->unitModel->findByCode($flatCode, $societyId);
                if (!$unit && $societyId > 0) {
                    $unit = $this->unitModel->findByCode($flatCode, 0);
                }
                if ($unit) {
                    $unitId = (int)$unit['id'];
                    if (empty($societyId)) {
                        $societyId = (int)$unit['society_id'];
                    }
                    $residents = $this->residentModel->findByUnitId($unitId, 0);
                    
                    // Filter active non-deleted residents
                    $activeTenants = array_values(array_filter($residents, function($r) {
                        return ($r['resident_type'] === 'Tenant' || ($r['role'] ?? '') === 'Tenant') && empty($r['is_deleted']);
                    }));
                    $activeOwners = array_values(array_filter($residents, function($r) {
                        return ($r['resident_type'] === 'Owner' || ($r['role'] ?? '') === 'Owner') && empty($r['is_deleted']);
                    }));

                    // TENANCY DIRECTIVE: If unit is rented to tenant(s), notify TENANT ONLY.
                    if (!empty($activeTenants)) {
                        $tenantNames = array_map(fn($t) => $t['name'] ?? $t['full_name'], $activeTenants);
                        $notifiedResidentName = implode(', ', $tenantNames);
                        $notifiedResidentType = 'Tenant';
                    } elseif (!empty($activeOwners)) {
                        $ownerNames = array_map(fn($o) => $o['name'] ?? $o['full_name'], $activeOwners);
                        $notifiedResidentName = implode(', ', $ownerNames);
                        $notifiedResidentType = 'Owner';
                    } elseif (!empty($unit['tenant_name'])) {
                        $notifiedResidentName = $unit['tenant_name'];
                        $notifiedResidentType = 'Tenant';
                    } elseif (!empty($unit['owner_name']) && $unit['owner_name'] !== 'Unsold / Builder') {
                        $notifiedResidentName = $unit['owner_name'];
                        $notifiedResidentType = 'Owner';
                    }
                }
            }

            // Fallbacks from input if provided
            if (empty($notifiedResidentName)) {
                $notifiedResidentName = $input['notifiedResidentName'] ?? ($input['hostName'] ?? null);
                $notifiedResidentType = $input['notifiedResidentType'] ?? 'Resident';
            }

            $hostDisplayName = $notifiedResidentName ? "{$notifiedResidentName} ({$notifiedResidentType})" : null;

            $data = [
                'visitor_code' => $code,
                'society_id' => $societyId,
                'unit_id' => $unitId,
                'name' => $visitorName,
                'visitor_type' => $visitorType,
                'phone' => $phone,
                'flat_visiting' => $flatCode,
                'host_name' => $hostDisplayName,
                'check_in_time' => $initialCheckIn,
                'status' => $initialStatus,
                'purpose' => $purpose,
                'gate_number' => $gateNumber,
                'vehicle_number' => $vehicleNumber,
                'pass_code' => $passCode,
                'photo_url' => $photoUrl,
                'notified_resident_name' => $notifiedResidentName,
                'notified_resident_type' => $notifiedResidentType,
                'approval_status' => $initialApproval
            ];

            $this->visitorModel->create($data, $societyId);

            if ($manageTx) {
                $db->commit();
            }

            // Dispatch Push Notification to Resident(s) of the flat
            if (!$isPreApproved) {
                try {
                    $pushService = new PushNotificationService();
                    $pushService->notifyGatepassGenerated(array_merge($data, [
                        'id' => $code,
                        'unit_id' => $unitId,
                        'name' => $visitorName
                    ]), $societyId);
                } catch (\Throwable $e) {
                    // Non-blocking notification dispatch
                }
            }

            return array_merge($input, [
                'id' => $code,
                'passCode' => $passCode,
                'status' => $initialStatus,
                'unitId' => $unitId,
                'notifiedResidentName' => $notifiedResidentName,
                'notifiedResidentType' => $notifiedResidentType,
                'hostName' => $hostDisplayName,
                'photoUrl' => $photoUrl,
                'approvalStatus' => $initialApproval
            ]);
        } catch (\Throwable $e) {
            if ($manageTx && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public function validatePass(string $passCode, ?string $gateNumber = null): array {
        $societyId = TenantContext::getSocietyId();
        $visitor = $this->visitorModel->findByPassCode($passCode, $societyId);
        if (!$visitor && $societyId > 0) {
            $visitor = $this->visitorModel->findByPassCode($passCode, 0);
        }
        if (!$visitor) {
            throw new Exception("Invalid or non-existent Gate Pass Code '{$passCode}'.");
        }

        return $visitor;
    }

    public function approvePass(string $visitorCode, ?string $approvedBy = null): bool {
        $db = Database::getConnection();
        $manageTx = !$db->inTransaction();
        if ($manageTx) {
            $db->beginTransaction();
        }

        try {
            $result = $this->visitorModel->updateApprovalStatus($visitorCode, 'Approved', 'Waiting at Gate');

            if ($manageTx) {
                $db->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($manageTx && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public function denyPass(string $visitorCode, ?string $reason = null): bool {
        $db = Database::getConnection();
        $manageTx = !$db->inTransaction();
        if ($manageTx) {
            $db->beginTransaction();
        }

        try {
            $result = $this->visitorModel->updateApprovalStatus($visitorCode, 'Denied', 'Denied Entry');

            if ($manageTx) {
                $db->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($manageTx && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public function allowInside(string $visitorCode): bool {
        $db = Database::getConnection();
        $manageTx = !$db->inTransaction();
        if ($manageTx) {
            $db->beginTransaction();
        }

        try {
            $vis = $this->visitorModel->findByCode($visitorCode, 0);
            if (!$vis) {
                $vis = $this->visitorModel->findByPassCode($visitorCode, 0);
            }
            if (!$vis && is_numeric($visitorCode)) {
                $stmt = $db->prepare("SELECT * FROM visitors WHERE id = ? AND is_deleted = 0 LIMIT 1");
                $stmt->execute([(int)$visitorCode]);
                $vis = $stmt->fetch();
            }
            if (!$vis) {
                throw new Exception("Visitor not found.");
            }

            $actualCode = $vis['visitor_code'] ?? ($vis['id'] ?? $visitorCode);
            $result = $this->visitorModel->allowInside($actualCode);
            if ($manageTx) {
                $db->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($manageTx && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public function checkout(string $visitorCode): bool {
        $db = Database::getConnection();
        $manageTx = !$db->inTransaction();
        if ($manageTx) {
            $db->beginTransaction();
        }

        try {
            $vis = $this->visitorModel->findByCode($visitorCode, 0);
            if (!$vis) {
                $vis = $this->visitorModel->findByPassCode($visitorCode, 0);
            }
            if (!$vis && is_numeric($visitorCode)) {
                $stmt = $db->prepare("SELECT * FROM visitors WHERE id = ? AND is_deleted = 0 LIMIT 1");
                $stmt->execute([(int)$visitorCode]);
                $vis = $stmt->fetch();
            }
            $actualCode = $vis ? ($vis['visitor_code'] ?? $visitorCode) : $visitorCode;

            $result = $this->visitorModel->checkout($actualCode);
            if ($manageTx) {
                $db->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($manageTx && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }
}
