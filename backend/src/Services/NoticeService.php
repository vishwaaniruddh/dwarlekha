<?php
namespace App\Services;

use App\Models\Notice;
use App\Config\Database;
use App\Config\TenantContext;
use InvalidArgumentException;
use Exception;

class NoticeService {
    private Notice $noticeModel;

    public function __construct(?Notice $noticeModel = null) {
        $this->noticeModel = $noticeModel ?: new Notice();
    }

    public function getNotices(?int $societyId = null, string $sortOrder = 'DESC'): array {
        return $this->noticeModel->getAll($societyId, $sortOrder);
    }

    public function getNoticeById(string $id): ?array {
        return $this->noticeModel->findById($id);
    }

    public function broadcast(array $input): array {
        if (empty($input['title']) || empty($input['content'])) {
            throw new InvalidArgumentException("Notice Title and Content are required.");
        }

        $societyId = !empty($input['society_id']) ? (int)$input['society_id'] : (!empty($input['societyId']) ? (int)$input['societyId'] : TenantContext::getSocietyId());
        $isPinned = !empty($input['pinned']) || !empty($input['is_pinned']);

        if ($isPinned && $societyId > 0) {
            $pinnedCount = $this->noticeModel->getPinnedCount($societyId);
            if ($pinnedCount >= 3) {
                throw new InvalidArgumentException("A society can have a maximum of 3 pinned notices. Please unpin an existing notice first.");
            }
        }

        $db = Database::getConnection();
        $manageTx = !$db->inTransaction();
        if ($manageTx) {
            $db->beginTransaction();
        }

        try {
            $code = $input['id'] ?? ('NTC-' . rand(200, 999));
            $targetScope = !empty($input['target_scope']) ? $input['target_scope'] : (!empty($input['targetScope']) ? $input['targetScope'] : 'ALL');
            $targetUnits = !empty($input['target_units']) ? $input['target_units'] : (!empty($input['targetUnits']) ? $input['targetUnits'] : []);
            if (!is_array($targetUnits)) {
                $targetUnits = array_values(array_filter(array_map('trim', explode(',', (string)$targetUnits))));
            }

            $data = [
                'notice_code' => $code,
                'society_id' => $societyId,
                'title' => trim($input['title']),
                'category' => $input['category'] ?? 'General',
                'priority' => $input['priority'] ?? ($input['urgency'] ?? 'Normal'),
                'content' => trim($input['content']),
                'target_scope' => $targetScope,
                'target_units' => $targetUnits,
                'is_pinned' => $isPinned ? 1 : 0,
                'author' => $input['author'] ?? ($input['author_name'] ?? 'Estate Management')
            ];

            $this->noticeModel->create($data, $societyId);

            // If notice is targeted to specific flats/units, send targeted notification
            $notificationStats = null;
            if ($targetScope === 'SPECIFIC_UNITS' && !empty($targetUnits)) {
                $notificationModel = new \App\Models\Notification();
                $notificationStats = $notificationModel->notifyNoticeTargeted($societyId, $data, $targetUnits);
            }

            if ($manageTx) {
                $db->commit();
            }

            return array_merge($input, [
                'id' => $code,
                'notice_code' => $code,
                'society_id' => $societyId,
                'societyId' => $societyId,
                'target_scope' => $targetScope,
                'target_units' => $targetUnits,
                'targetUnits' => $targetUnits,
                'notification_stats' => $notificationStats,
                'is_pinned' => $isPinned,
                'pinned' => $isPinned,
                'date' => 'Today, Just now'
            ]);
        } catch (\Throwable $e) {
            if ($manageTx && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public function updateNotice(string $id, array $input): array {
        if (empty($input['title']) || empty($input['content'])) {
            throw new InvalidArgumentException("Notice Title and Content are required.");
        }

        $existing = $this->noticeModel->findById($id);
        if (!$existing) {
            throw new Exception("Notice not found or deleted.");
        }

        $isPinned = !empty($input['pinned']) || !empty($input['is_pinned']);
        $targetSoc = !empty($input['society_id']) ? (int)$input['society_id'] : (!empty($input['societyId']) ? (int)$input['societyId'] : (int)$existing['society_id']);

        if ($isPinned && $targetSoc > 0) {
            $pinnedCount = $this->noticeModel->getPinnedCount($targetSoc, (int)$existing['dbId']);
            if ($pinnedCount >= 3) {
                throw new InvalidArgumentException("A society can have a maximum of 3 pinned notices. Please unpin an existing notice first.");
            }
        }

        $db = Database::getConnection();
        $manageTx = !$db->inTransaction();
        if ($manageTx) {
            $db->beginTransaction();
        }

        try {
            $this->noticeModel->update($id, $input);

            if ($manageTx) {
                $db->commit();
            }

            return $this->noticeModel->findById($id) ?: array_merge($existing, $input);
        } catch (\Throwable $e) {
            if ($manageTx && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public function deleteNotice(string $id): bool {
        $db = Database::getConnection();
        $manageTx = !$db->inTransaction();
        if ($manageTx) {
            $db->beginTransaction();
        }

        try {
            $success = $this->noticeModel->delete($id);

            if ($manageTx) {
                $db->commit();
            }
            return $success;
        } catch (\Throwable $e) {
            if ($manageTx && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public function togglePin(string $id): bool {
        $existing = $this->noticeModel->findById($id);
        if (!$existing) {
            throw new Exception("Notice not found or deleted.");
        }

        $currentlyPinned = !empty($existing['is_pinned']) || !empty($existing['pinned']);
        $newPinned = !$currentlyPinned;

        if ($newPinned) {
            $societyId = (int)$existing['society_id'];
            if ($societyId > 0) {
                $pinnedCount = $this->noticeModel->getPinnedCount($societyId, (int)$existing['dbId']);
                if ($pinnedCount >= 3) {
                    throw new InvalidArgumentException("A society can have a maximum of 3 pinned notices. Please unpin an existing notice first.");
                }
            }
        }

        $db = Database::getConnection();
        $manageTx = !$db->inTransaction();
        if ($manageTx) {
            $db->beginTransaction();
        }

        try {
            $this->noticeModel->setPinStatus($id, $newPinned ? 1 : 0);

            if ($manageTx) {
                $db->commit();
            }
            return $newPinned;
        } catch (\Throwable $e) {
            if ($manageTx && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }
}
