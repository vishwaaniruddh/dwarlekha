<?php
namespace App\Controllers;

use App\Config\Database;
use App\Config\Response;
use App\Services\PushNotificationService;
use Throwable;

class PushNotificationController {
    public function registerToken(): void {
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $pushToken = $input['push_token'] ?? ($input['token'] ?? null);
        $userId = $input['user_id'] ?? null;
        $unitId = $input['unit_id'] ?? null;
        $email = $input['email'] ?? null;

        if (empty($pushToken)) {
            Response::json(['error' => 'Push token is required.'], 400);
            return;
        }

        $db = Database::getConnection();
        try {
            // 1. Update user record if userId or email provided
            if ($userId) {
                $stmt = $db->prepare("UPDATE users SET push_token = ? WHERE id = ?");
                $stmt->execute([$pushToken, $userId]);
            } elseif ($email) {
                $stmt = $db->prepare("UPDATE users SET push_token = ? WHERE email = ?");
                $stmt->execute([$pushToken, $email]);
            }

            // 2. Update resident record if unitId provided
            if ($unitId) {
                $stmt = $db->prepare("UPDATE residents SET push_token = ? WHERE unit_id = ?");
                $stmt->execute([$pushToken, $unitId]);
            }

            Response::json([
                'success' => true,
                'message' => 'Push token registered successfully.',
                'token' => $pushToken
            ]);
        } catch (Throwable $e) {
            Response::json(['error' => 'Database error: ' . $e->getMessage()], 500);
        }
    }

    public function testPush(): void {
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $token = $input['push_token'] ?? ($input['token'] ?? null);

        if (empty($token)) {
            Response::json(['error' => 'Push token is required to send test push.'], 400);
            return;
        }

        $service = new PushNotificationService();
        $res = $service->send(
            $token,
            '🔔 Test Gate Notification',
            'Your phone is successfully connected to the Society Gate Notification System!',
            ['type' => 'test_push', 'timestamp' => date('Y-m-d H:i:s')]
        );

        Response::json($res);
    }
}
