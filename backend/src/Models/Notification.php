<?php
namespace App\Models;

use App\Config\Database;
use PDO;

class Notification extends BaseModel {
    protected string $table = 'notifications';

    /**
     * Create a notification record (Atomic & Soft-delete compliant)
     */
    public function create(array $data): int {
        $db = Database::getConnection();
        $stmt = $db->prepare("INSERT INTO {$this->table} 
            (society_id, user_id, unit_id, target_role, type, title, message, data_payload, is_read, is_deleted) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0)");
        
        $payload = !empty($data['data_payload']) 
            ? (is_string($data['data_payload']) ? $data['data_payload'] : json_encode($data['data_payload']))
            : null;

        $stmt->execute([
            $data['society_id'] ?? 1,
            $data['user_id'] ?? null,
            $data['unit_id'] ?? null,
            $data['target_role'] ?? null,
            $data['type'] ?? 'GENERAL',
            $data['title'],
            $data['message'],
            $payload
        ]);

        return (int)$db->lastInsertId();
    }

    /**
     * Send Bill Generation Notification to all occupants (Owners & Tenants) of a unit
     */
    public function notifyBillGenerated(int $societyId, int $unitId, array $billData): void {
        $db = Database::getConnection();

        // 1. Fetch all residents / users associated with this unit
        $stmt = $db->prepare("SELECT r.id as resident_id, r.user_id, r.resident_type, u.unit_code, usr.full_name, usr.email, usr.phone 
            FROM units u 
            LEFT JOIN unit_occupancies uo ON u.id = uo.unit_id 
            LEFT JOIN residents r ON uo.resident_id = r.id AND r.is_deleted = 0 
            LEFT JOIN users usr ON r.user_id = usr.id AND usr.is_deleted = 0 
            WHERE u.id = ?");
        $stmt->execute([$unitId]);
        $residents = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $unitCode = $billData['unit_code'] ?? ($residents[0]['unit_code'] ?? 'Unit');
        $billNum = $billData['bill_number'] ?? 'Bill';
        $amountFormatted = number_format((float)($billData['total_amount'] ?? 0), 2);
        $dueDate = $billData['due_date'] ?? date('Y-m-d', strtotime('+15 days'));

        $title = "📄 Maintenance Bill Generated: {$billNum}";
        $msg = "Maintenance invoice of ₹{$amountFormatted} has been generated for your Flat {$unitCode}. Due Date: {$dueDate}. Please pay online via Razorpay to avoid late fee.";

        $notifiedUserIds = [];

        foreach ($residents as $res) {
            $userId = $res['user_id'];
            if ($userId && !in_array($userId, $notifiedUserIds)) {
                $notifiedUserIds[] = $userId;
                $this->create([
                    'society_id' => $societyId,
                    'user_id' => $userId,
                    'unit_id' => $unitId,
                    'target_role' => 'resident',
                    'type' => 'BILL_GENERATED',
                    'title' => $title,
                    'message' => $msg,
                    'data_payload' => [
                        'bill_id' => $billData['id'] ?? null,
                        'bill_number' => $billNum,
                        'total_amount' => $billData['total_amount'] ?? 0,
                        'unit_code' => $unitCode,
                        'due_date' => $dueDate
                    ]
                ]);
            }
        }

        // If unit has no registered user accounts linked yet, create a unit-scoped notification
        if (empty($notifiedUserIds)) {
            $this->create([
                'society_id' => $societyId,
                'user_id' => null,
                'unit_id' => $unitId,
                'target_role' => 'resident',
                'type' => 'BILL_GENERATED',
                'title' => $title,
                'message' => $msg,
                'data_payload' => [
                    'bill_id' => $billData['id'] ?? null,
                    'bill_number' => $billNum,
                    'total_amount' => $billData['total_amount'] ?? 0,
                    'unit_code' => $unitCode,
                    'due_date' => $dueDate
                ]
            ]);
        }
    }

    /**
     * Send Payment Received Notification to Admins and Residents
     */
    public function notifyPaymentReceived(int $societyId, array $paymentData): void {
        $db = Database::getConnection();

        $amountFormatted = number_format((float)($paymentData['amount'] ?? 0), 2);
        $receiptNum = $paymentData['receipt_number'] ?? 'Receipt';
        $mode = $paymentData['payment_mode'] ?? 'Razorpay';
        $unitCode = $paymentData['unit_code'] ?? 'Unit';
        $residentName = $paymentData['resident_name'] ?? 'Resident';

        // 1. Notify Society Admins and Superadmins
        $adminTitle = "💰 Payment Received: ₹{$amountFormatted} (Unit {$unitCode})";
        $adminMsg = "Payment of ₹{$amountFormatted} received for Unit {$unitCode} from {$residentName} via {$mode}. Receipt #{$receiptNum}.";

        $this->create([
            'society_id' => $societyId,
            'user_id' => null,
            'unit_id' => $paymentData['unit_id'] ?? null,
            'target_role' => 'admin',
            'type' => 'PAYMENT_RECEIVED',
            'title' => $adminTitle,
            'message' => $adminMsg,
            'data_payload' => $paymentData
        ]);

        // 2. Notify Occupants / Payer
        $payerTitle = "✅ Payment Confirmed — Receipt #{$receiptNum}";
        $payerMsg = "Your payment of ₹{$amountFormatted} for Flat {$unitCode} via {$mode} has been received and verified. Thank you!";

        if (!empty($paymentData['user_id'])) {
            $this->create([
                'society_id' => $societyId,
                'user_id' => $paymentData['user_id'],
                'unit_id' => $paymentData['unit_id'] ?? null,
                'target_role' => 'resident',
                'type' => 'PAYMENT_RECEIVED',
                'title' => $payerTitle,
                'message' => $payerMsg,
                'data_payload' => $paymentData
            ]);
        } elseif (!empty($paymentData['unit_id'])) {
            $this->create([
                'society_id' => $societyId,
                'user_id' => null,
                'unit_id' => $paymentData['unit_id'],
                'target_role' => 'resident',
                'type' => 'PAYMENT_RECEIVED',
                'title' => $payerTitle,
                'message' => $payerMsg,
                'data_payload' => $paymentData
            ]);
        }
    }

    /**
     * Send Notice Notification to occupants of selected units / flats
     */
    public function notifyNoticeTargeted(int $societyId, array $noticeData, array $targetUnitCodesOrIds): array {
        $db = Database::getConnection();
        if (empty($targetUnitCodesOrIds)) {
            return ['notified_users_count' => 0, 'notified_units_count' => 0];
        }

        $cleanTargets = array_values(array_filter(array_map('trim', $targetUnitCodesOrIds)));
        if (empty($cleanTargets)) {
            return ['notified_users_count' => 0, 'notified_units_count' => 0];
        }

        // 1. Resolve unit IDs and unit codes
        $inPlaceholders = implode(',', array_fill(0, count($cleanTargets), '?'));
        
        $sql = "SELECT u.id as unit_id, u.unit_code, u.society_id, r.id as resident_id, r.user_id, usr.full_name, usr.email, usr.phone
                FROM units u
                LEFT JOIN unit_occupancies uo ON u.id = uo.unit_id AND uo.is_deleted = 0
                LEFT JOIN residents r ON uo.resident_id = r.id AND r.is_deleted = 0
                LEFT JOIN users usr ON r.user_id = usr.id AND usr.is_deleted = 0
                WHERE u.society_id = ? AND u.is_deleted = 0 
                AND (u.unit_code IN ($inPlaceholders) OR u.id IN ($inPlaceholders))";
        
        $params = array_merge([$societyId], $cleanTargets, $cleanTargets);
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $noticeTitle = $noticeData['title'] ?? 'Notice Announcement';
        $noticeCode = $noticeData['notice_code'] ?? ($noticeData['id'] ?? 'NTC');
        $rawContent = $noticeData['content'] ?? '';
        $snippet = strlen($rawContent) > 120 ? substr($rawContent, 0, 117) . '...' : $rawContent;

        $notifiedUsers = [];
        $unitsNotified = [];

        foreach ($rows as $row) {
            $unitId = (int)$row['unit_id'];
            $unitCode = $row['unit_code'];
            $userId = !empty($row['user_id']) ? (int)$row['user_id'] : null;

            $unitsNotified[$unitCode] = true;

            $title = "📢 Notice for Flat {$unitCode}: {$noticeTitle}";
            $msg = "A circular has been issued for your flat {$unitCode}: {$snippet}";

            if ($userId && !in_array($userId, $notifiedUsers)) {
                $notifiedUsers[] = $userId;
                $this->create([
                    'society_id' => $societyId,
                    'user_id' => $userId,
                    'unit_id' => $unitId,
                    'target_role' => 'resident',
                    'type' => 'NOTICE_TARGETED',
                    'title' => $title,
                    'message' => $msg,
                    'data_payload' => [
                        'notice_code' => $noticeCode,
                        'notice_title' => $noticeTitle,
                        'category' => $noticeData['category'] ?? 'General',
                        'priority' => $noticeData['priority'] ?? 'Normal',
                        'unit_code' => $unitCode,
                        'unit_id' => $unitId
                    ]
                ]);
            }
        }

        // For units that have no registered user accounts, create unit-scoped notification
        $allMatchedUnits = [];
        foreach ($rows as $row) {
            $allMatchedUnits[(int)$row['unit_id']] = $row['unit_code'];
        }

        foreach ($allMatchedUnits as $uId => $uCode) {
            $hasUser = false;
            foreach ($rows as $row) {
                if ((int)$row['unit_id'] === $uId && !empty($row['user_id'])) {
                    $hasUser = true;
                    break;
                }
            }

            if (!$hasUser) {
                $title = "📢 Notice for Flat {$uCode}: {$noticeTitle}";
                $msg = "A circular has been issued for your flat {$uCode}: {$snippet}";

                $this->create([
                    'society_id' => $societyId,
                    'user_id' => null,
                    'unit_id' => $uId,
                    'target_role' => 'resident',
                    'type' => 'NOTICE_TARGETED',
                    'title' => $title,
                    'message' => $msg,
                    'data_payload' => [
                        'notice_code' => $noticeCode,
                        'notice_title' => $noticeTitle,
                        'category' => $noticeData['category'] ?? 'General',
                        'priority' => $noticeData['priority'] ?? 'Normal',
                        'unit_code' => $uCode,
                        'unit_id' => $uId
                    ]
                ]);
            }
        }

        return [
            'notified_users_count' => count($notifiedUsers),
            'notified_units_count' => count($unitsNotified)
        ];
    }

    /**
     * Get Notifications for a user / role / society
     */
    public function getForUser(int $societyId, ?int $userId = null, ?string $roleCode = null, ?int $unitId = null): array {
        $db = Database::getConnection();

        $where = ["n.is_deleted = 0"];
        $params = [];

        if ($societyId > 0) {
            $where[] = "(n.society_id = ? OR n.society_id = 0)";
            $params[] = $societyId;
        }

        if ($roleCode === 'superadmin') {
            // Superadmin sees all admin and system notifications
            $where[] = "(n.target_role IN ('admin', 'all') OR n.user_id = ? OR n.target_role IS NULL)";
            $params[] = $userId ?: 0;
        } elseif ($roleCode === 'admin' || $roleCode === 'society_admin' || $roleCode === 'accountant') {
            $where[] = "(n.target_role IN ('admin', 'all') OR n.user_id = ?)";
            $params[] = $userId ?: 0;
        } else {
            // Resident
            if ($userId && $unitId) {
                $where[] = "(n.user_id = ? OR n.unit_id = ? OR n.target_role IN ('resident', 'all'))";
                $params[] = $userId;
                $params[] = $unitId;
            } elseif ($userId) {
                $where[] = "(n.user_id = ? OR n.target_role IN ('resident', 'all'))";
                $params[] = $userId;
            } elseif ($unitId) {
                $where[] = "(n.unit_id = ? OR n.target_role IN ('resident', 'all'))";
                $params[] = $unitId;
            }
        }

        $sql = "SELECT n.* FROM {$this->table} n 
            WHERE " . implode(" AND ", $where) . " 
            ORDER BY n.id DESC LIMIT 50";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
