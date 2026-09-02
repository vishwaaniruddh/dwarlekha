<?php
namespace App\Services;

use App\Config\Database;
use PDO;
use Throwable;

class PushNotificationService {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getConnection();
    }

    /**
     * Dispatch an Expo Push Notification to a single or multiple tokens.
     */
    public function send(string|array $to, string $title, string $body, array $data = [], ?int $societyId = 3, ?int $unitId = null, ?int $userId = null): array {
        $tokens = is_array($to) ? array_filter($to) : [$to];
        if (empty($tokens)) {
            return ['success' => false, 'message' => 'No valid push tokens provided.'];
        }

        $messages = [];
        foreach ($tokens as $token) {
            if (!empty($token) && (str_starts_with($token, 'ExponentPushToken') || str_starts_with($token, 'ExpoPushToken'))) {
                $messages[] = [
                    'to' => $token,
                    'sound' => 'default',
                    'title' => $title,
                    'body' => $body,
                    'data' => $data,
                    'priority' => 'high',
                    'channelId' => 'gate-alerts',
                    '_displayInForeground' => true
                ];
            }
        }

        if (empty($messages)) {
            return ['success' => false, 'message' => 'No Expo push tokens in list.'];
        }

        $ch = curl_init('https://exp.host/--/api/v2/push/send');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
            'Accept-Encoding: gzip, deflate'
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(count($messages) === 1 ? $messages[0] : $messages));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $resultData = json_decode($response, true) ?: [];

        // Log to database
        try {
            $stmt = $this->db->prepare("INSERT INTO push_notifications_log 
                (society_id, user_id, unit_id, push_token, title, body, payload_data, status, expo_ticket_id) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

            $ticketId = $resultData['data']['id'] ?? ($resultData['data'][0]['id'] ?? null);
            $tokenStr = implode(',', $tokens);

            $stmt->execute([
                $societyId ?: 3,
                $userId,
                $unitId,
                $tokenStr,
                $title,
                $body,
                json_encode($data),
                ($httpCode >= 200 && $httpCode < 300) ? 'delivered' : 'failed',
                $ticketId
            ]);
        } catch (Throwable $e) {
            // Ignore logging error to prevent blocking execution
        }

        return [
            'success' => ($httpCode >= 200 && $httpCode < 300),
            'http_code' => $httpCode,
            'response' => $resultData,
            'error' => $curlError
        ];
    }

    /**
     * Notify resident(s) when a new gatepass is created/generated at security.
     */
    public function notifyGatepassGenerated(array $visitor, ?int $societyId = 3): array {
        $flatVisiting = $visitor['flat_visiting'] ?? ($visitor['flat_number'] ?? ($visitor['flatVisiting'] ?? ''));
        $unitId = $visitor['unit_id'] ?? null;
        $visitorName = !empty($visitor['name']) ? trim($visitor['name']) : (!empty($visitor['visitor_name']) ? trim($visitor['visitor_name']) : 'Visitor');
        $visitorType = $visitor['type'] ?? ($visitor['visitor_type'] ?? 'Guest');
        $passCode = $visitor['visitor_code'] ?? ($visitor['pass_code'] ?? ($visitor['id'] ?? 'GATE-PASS'));

        // Look up push tokens for this unit/flat in users and residents table
        $tokens = [];

        // 1. Check residents table by unitId
        if (!empty($unitId)) {
            $stmt = $this->db->prepare("SELECT push_token FROM residents WHERE unit_id = ? AND push_token IS NOT NULL AND is_deleted = 0");
            $stmt->execute([$unitId]);
            $tokens = array_merge($tokens, $stmt->fetchAll(PDO::FETCH_COLUMN));
        }

        // 2. Check residents table by flat code
        if (!empty($flatVisiting)) {
            $stmt = $this->db->prepare("SELECT r.push_token FROM residents r 
                JOIN units un ON r.unit_id = un.id 
                WHERE (un.unit_code = ? OR un.unit_code LIKE ?) 
                AND r.push_token IS NOT NULL AND r.is_deleted = 0");
            $stmt->execute([$flatVisiting, "%$flatVisiting%"]);
            $tokens = array_merge($tokens, $stmt->fetchAll(PDO::FETCH_COLUMN));
        }

        // 3. Also check users table
        $stmt = $this->db->prepare("SELECT push_token FROM users WHERE push_token IS NOT NULL AND is_deleted = 0");
        $stmt->execute();
        $tokens = array_merge($tokens, $stmt->fetchAll(PDO::FETCH_COLUMN));

        $tokens = array_unique(array_filter($tokens));

        $title = "🚪 {$visitorName} is at the Main Gate";
        $body = "{$visitorName} ({$visitorType}) is requesting entry to Unit {$flatVisiting}. Tap to Approve or Deny.";

        $payloadData = [
            'type' => 'gatepass_approval',
            'visitor_id' => $visitor['id'] ?? null,
            'visitor_code' => $passCode,
            'visitor_name' => $visitorName,
            'name' => $visitorName,
            'visitor_type' => $visitorType,
            'flat_number' => $flatVisiting,
            'unit_id' => $unitId,
            'photo_url' => $visitor['photo_url'] ?? ($visitor['photoUrl'] ?? null),
            'photoUrl' => $visitor['photo_url'] ?? ($visitor['photoUrl'] ?? null),
            'created_at' => date('Y-m-d H:i:s')
        ];

        return $this->send($tokens, $title, $body, $payloadData, $societyId, $unitId);
    }

    /**
     * Notify security guard/gatekeeper when a resident Approves or Rejects a visitor
     */
    public function notifyGatekeeperDecision(array $visitor, bool $isApproved, int $societyId = 0): array {
        $visitorName = $visitor['name'] ?? ($visitor['visitor_name'] ?? 'Visitor');
        $flatVisiting = $visitor['flat_visiting'] ?? ($visitor['flatVisiting'] ?? 'Resident Unit');
        $passCode = $visitor['visitor_code'] ?? ($visitor['pass_code'] ?? ($visitor['passCode'] ?? 'GATE-PASS'));
        $decision = $isApproved ? 'Approved' : 'Denied';

        // Query security guard and gatekeeper push tokens
        $tokens = [];
        $stmt = $this->db->prepare("SELECT u.push_token FROM users u 
            JOIN roles r ON u.role_id = r.id 
            WHERE (r.role_code LIKE '%guard%' OR r.role_code LIKE '%security%' OR r.role_code LIKE '%gate%')
            AND u.push_token IS NOT NULL AND u.is_deleted = 0
            AND (u.society_id = ? OR u.society_id IS NULL OR ? = 0)");
        $stmt->execute([$societyId, $societyId]);
        $tokens = array_merge($tokens, $stmt->fetchAll(PDO::FETCH_COLUMN));

        $tokens = array_unique(array_filter($tokens));

        $title = $isApproved 
            ? "✅ Entry Approved: {$visitorName}" 
            : "🛑 Entry Rejected: {$visitorName}";
        
        $body = $isApproved 
            ? "Flat {$flatVisiting} APPROVED entry for {$visitorName}. You may allow them inside." 
            : "Flat {$flatVisiting} REJECTED entry for {$visitorName}. Do NOT allow entry.";

        $payloadData = [
            'type' => 'gate_decision',
            'decision' => $decision,
            'is_approved' => $isApproved,
            'visitor_id' => $visitor['id'] ?? null,
            'visitor_code' => $passCode,
            'visitor_name' => $visitorName,
            'name' => $visitorName,
            'flat_visiting' => $flatVisiting,
            'flat_number' => $flatVisiting,
            'photo_url' => $visitor['photo_url'] ?? ($visitor['photoUrl'] ?? null),
            'timestamp' => time()
        ];

        return $this->send($tokens, $title, $body, $payloadData, $societyId);
    }
}
