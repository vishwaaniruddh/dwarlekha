<?php
namespace App\Models;

use PDO;

class Visitor extends BaseModel {
    public function getAll(?int $societyId = null): array {
        $societyId = ($societyId !== null) ? $societyId : $this->getSocietyId();
        if ($societyId === 0) {
            $stmt = $this->db->query("SELECT * FROM visitors WHERE is_deleted = 0 ORDER BY society_id ASC, id DESC");
        } else {
            $stmt = $this->db->prepare("SELECT * FROM visitors WHERE society_id = ? AND is_deleted = 0 ORDER BY id DESC");
            $stmt->execute([$societyId]);
        }
        $rows = $stmt->fetchAll();

        return array_map(function($v) {
            return [
                'id' => $v['visitor_code'],
                'visitorDbId' => (int)$v['id'],
                'societyId' => (int)$v['society_id'],
                'unitId' => $v['unit_id'] ? (int)$v['unit_id'] : null,
                'name' => $v['name'],
                'type' => $v['visitor_type'],
                'flatVisiting' => $v['flat_visiting'],
                'flatVisitingCode' => $v['flat_visiting'],
                'hostName' => $v['host_name'],
                'phone' => $v['phone'],
                'checkInTime' => $v['check_in_time'],
                'checkOutTime' => $v['check_out_time'],
                'status' => $v['status'] ?? 'Waiting at Gate',
                'purpose' => $v['purpose'],
                'gateNumber' => $v['gate_number'],
                'vehicleNumber' => $v['vehicle_number'],
                'passCode' => $v['pass_code'],
                'photoUrl' => $v['photo_url'] ?? null,
                'photo_url' => $v['photo_url'] ?? null,
                'notifiedResidentName' => $v['notified_resident_name'] ?? null,
                'notifiedResidentType' => $v['notified_resident_type'] ?? null,
                'approvalStatus' => $v['approval_status'] ?? 'Pending Approval',
                'createdAt' => $v['created_at']
            ];
        }, $rows);
    }

    public function findByCode(string $visitorCode, ?int $societyId = null): ?array {
        $societyId = ($societyId !== null) ? $societyId : $this->getSocietyId();
        if ($societyId === 0) {
            $stmt = $this->db->prepare("SELECT * FROM visitors WHERE visitor_code = ? AND is_deleted = 0");
            $stmt->execute([$visitorCode]);
        } else {
            $stmt = $this->db->prepare("SELECT * FROM visitors WHERE visitor_code = ? AND society_id = ? AND is_deleted = 0");
            $stmt->execute([$visitorCode, $societyId]);
        }
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByPassCode(string $passCode, ?int $societyId = null): ?array {
        $societyId = ($societyId !== null) ? $societyId : $this->getSocietyId();
        $cleanPass = strtoupper(trim(preg_replace('/^#/', '', $passCode)));
        $cleanPassAlt = str_starts_with($cleanPass, 'PASS-') ? substr($cleanPass, 5) : ('PASS-' . $cleanPass);

        $sql = "SELECT * FROM visitors WHERE (pass_code = ? OR pass_code = ? OR pass_code LIKE ?) AND is_deleted = 0";
        $params = [$cleanPass, $cleanPassAlt, "%{$cleanPass}%"];

        if ($societyId > 0) {
            $sql .= " AND society_id = ?";
            $params[] = $societyId;
        }

        $stmt = $this->db->prepare($sql . " ORDER BY id DESC LIMIT 1");
        $stmt->execute($params);
        $row = $stmt->fetch();
        if (!$row) return null;

        return [
            'id' => $row['visitor_code'],
            'visitorDbId' => (int)$row['id'],
            'societyId' => (int)$row['society_id'],
            'unitId' => $row['unit_id'] ? (int)$row['unit_id'] : null,
            'name' => $row['name'],
            'type' => $row['visitor_type'],
            'flatVisiting' => $row['flat_visiting'],
            'flatVisitingCode' => $row['flat_visiting'],
            'hostName' => $row['host_name'],
            'phone' => $row['phone'],
            'checkInTime' => $row['check_in_time'],
            'checkOutTime' => $row['check_out_time'],
            'status' => $row['status'] ?? 'Waiting at Gate',
            'purpose' => $row['purpose'],
            'gateNumber' => $row['gate_number'],
            'vehicleNumber' => $row['vehicle_number'],
            'passCode' => $row['pass_code'],
            'photoUrl' => $row['photo_url'] ?? null,
            'photo_url' => $row['photo_url'] ?? null,
            'notifiedResidentName' => $row['notified_resident_name'] ?? null,
            'notifiedResidentType' => $row['notified_resident_type'] ?? null,
            'approvalStatus' => $row['approval_status'] ?? 'Pending Approval',
            'createdAt' => $row['created_at']
        ];
    }

    public function isPassCodeTaken(string $passCode, int $societyId): bool {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM visitors WHERE pass_code = ? AND society_id = ? AND is_deleted = 0");
        $stmt->execute([$passCode, $societyId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public function create(array $data, ?int $societyId = null): int {
        $societyId = $societyId ?: $this->getSocietyId();
        $status = $data['status'] ?? 'Waiting at Gate';
        $approvalStatus = $data['approval_status'] ?? ($data['approvalStatus'] ?? 'Pending Approval');

        $stmt = $this->db->prepare("INSERT INTO visitors 
            (visitor_code, society_id, unit_id, name, visitor_type, phone, flat_visiting, host_name, check_in_time, status, purpose, gate_number, vehicle_number, pass_code, photo_url, notified_resident_name, notified_resident_type, approval_status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        $stmt->execute([
            $data['visitor_code'],
            $societyId,
            $data['unit_id'] ?? null,
            $data['name'],
            $data['visitor_type'] ?? ($data['type'] ?? 'Guest'),
            $data['phone'] ?? '+91 98200-00000',
            $data['flat_visiting'] ?? ($data['flatVisiting'] ?? ''),
            $data['host_name'] ?? ($data['hostName'] ?? null),
            $data['check_in_time'] ?? ($data['checkInTime'] ?? 'Awaiting Entry'),
            $status,
            $data['purpose'] ?? 'General Visit',
            $data['gate_number'] ?? ($data['gateNumber'] ?? 'Gate 1 (North)'),
            $data['vehicle_number'] ?? ($data['vehicleNumber'] ?? 'Walk-in'),
            $data['pass_code'] ?? ($data['passCode'] ?? ('PASS-' . mt_rand(1000, 9999))),
            $data['photo_url'] ?? ($data['photoUrl'] ?? null),
            $data['notified_resident_name'] ?? ($data['notifiedResidentName'] ?? null),
            $data['notified_resident_type'] ?? ($data['notifiedResidentType'] ?? null),
            $approvalStatus
        ]);

        return (int)$this->db->lastInsertId();
    }

    public function updateApprovalStatus(string $visitorCode, string $approvalStatus, ?string $status = null, ?int $societyId = null): bool {
        $societyId = ($societyId !== null) ? $societyId : $this->getSocietyId();
        
        if ($status !== null) {
            $sql = "UPDATE visitors SET approval_status = ?, status = ? WHERE (visitor_code = ? OR id = ? OR pass_code = ?) AND is_deleted = 0";
            $params = [$approvalStatus, $status, $visitorCode, $visitorCode, $visitorCode];
        } else {
            $sql = "UPDATE visitors SET approval_status = ? WHERE (visitor_code = ? OR id = ? OR pass_code = ?) AND is_deleted = 0";
            $params = [$approvalStatus, $visitorCode, $visitorCode, $visitorCode];
        }

        if ($societyId > 0) {
            $sql .= " AND society_id = ?";
            $params[] = $societyId;
        }

        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    public function allowInside(string $visitorCode, ?int $societyId = null): bool {
        $societyId = ($societyId !== null) ? $societyId : $this->getSocietyId();
        $sql = "UPDATE visitors SET status = 'Inside', check_in_time = DATE_FORMAT(NOW(), '%d %b, %h:%i %p') WHERE (visitor_code = ? OR id = ? OR pass_code = ?) AND is_deleted = 0";
        $params = [$visitorCode, $visitorCode, $visitorCode];

        if ($societyId > 0) {
            $sql .= " AND society_id = ?";
            $params[] = $societyId;
        }

        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    public function checkout(string $visitorCode, ?int $societyId = null): bool {
        $societyId = ($societyId !== null) ? $societyId : $this->getSocietyId();
        if ($societyId === 0) {
            $stmt = $this->db->prepare("UPDATE visitors SET status = 'Checked Out', check_out_time = DATE_FORMAT(NOW(), '%d %b, %h:%i %p') WHERE (visitor_code = ? OR id = ?) AND is_deleted = 0");
            return $stmt->execute([$visitorCode, $visitorCode]);
        }
        $stmt = $this->db->prepare("UPDATE visitors SET status = 'Checked Out', check_out_time = DATE_FORMAT(NOW(), '%d %b, %h:%i %p') WHERE (visitor_code = ? OR id = ?) AND society_id = ? AND is_deleted = 0");
        return $stmt->execute([$visitorCode, $visitorCode, $societyId]);
    }

    public function delete(string $visitorCode, ?int $societyId = null): bool {
        $societyId = ($societyId !== null) ? $societyId : $this->getSocietyId();
        if ($societyId === 0) {
            $stmt = $this->db->prepare("UPDATE visitors SET is_deleted = 1, deleted_at = NOW() WHERE (visitor_code = ? OR id = ?)");
            return $stmt->execute([$visitorCode, $visitorCode]);
        }
        $stmt = $this->db->prepare("UPDATE visitors SET is_deleted = 1, deleted_at = NOW() WHERE (visitor_code = ? OR id = ?) AND society_id = ?");
        return $stmt->execute([$visitorCode, $visitorCode, $societyId]);
    }
}
