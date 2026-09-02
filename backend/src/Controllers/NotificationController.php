<?php
namespace App\Controllers;

use App\Config\TenantContext;
use App\Models\Notification;
use App\Config\Database;

class NotificationController extends BaseController {
    private Notification $notificationModel;

    public function __construct() {
        $this->notificationModel = new Notification();
    }

    public function index(): void {
        $societyId = TenantContext::resolve();
        $user = $this->getCurrentUser();
        $userId = $user['id'] ?? null;
        $roleCode = $user['role']['code'] ?? ($user['role_code'] ?? null);
        $unitId = $user['unit_id'] ?? null;

        $notifications = $this->notificationModel->getForUser($societyId, $userId, $roleCode, $unitId);
        $this->success($notifications);
    }

    public function markAsRead(int $id): void {
        $db = Database::getConnection();
        $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?");
        $stmt->execute([$id]);
        $this->success(null, 'Notification marked as read');
    }

    public function markAllAsRead(): void {
        $societyId = TenantContext::resolve();
        $user = $this->getCurrentUser();
        $userId = $user['id'] ?? null;

        $db = Database::getConnection();
        if ($userId) {
            $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? OR society_id = ?");
            $stmt->execute([$userId, $societyId]);
        } else {
            $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE society_id = ?");
            $stmt->execute([$societyId]);
        }
        $this->success(null, 'All notifications marked as read');
    }
}
