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

    public function getNotices(?int $societyId = null): array {
        return $this->noticeModel->getAll($societyId);
    }

    public function getNoticeById(string $id): ?array {
        return $this->noticeModel->findById($id);
    }

    public function broadcast(array $input): array {
        if (empty($input['title']) || empty($input['content'])) {
            throw new InvalidArgumentException("Notice Title and Content are required.");
        }

        $db = Database::getConnection();
        $manageTx = !$db->inTransaction();
        if ($manageTx) {
            $db->beginTransaction();
        }

        try {
            $societyId = !empty($input['society_id']) ? (int)$input['society_id'] : (!empty($input['societyId']) ? (int)$input['societyId'] : TenantContext::getSocietyId());
            $code = $input['id'] ?? ('NTC-' . rand(200, 999));

            $data = [
                'notice_code' => $code,
                'society_id' => $societyId,
                'title' => trim($input['title']),
                'category' => $input['category'] ?? 'General',
                'priority' => $input['priority'] ?? ($input['urgency'] ?? 'Normal'),
                'content' => trim($input['content']),
                'is_pinned' => !empty($input['pinned']) || !empty($input['is_pinned']),
                'author' => $input['author'] ?? ($input['author_name'] ?? 'Estate Management')
            ];

            $this->noticeModel->create($data, $societyId);

            if ($manageTx) {
                $db->commit();
            }

            return array_merge($input, [
                'id' => $code,
                'society_id' => $societyId,
                'societyId' => $societyId,
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

        $db = Database::getConnection();
        $manageTx = !$db->inTransaction();
        if ($manageTx) {
            $db->beginTransaction();
        }

        try {
            $existing = $this->noticeModel->findById($id);
            if (!$existing) {
                throw new Exception("Notice not found or deleted.");
            }

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
        $db = Database::getConnection();
        $manageTx = !$db->inTransaction();
        if ($manageTx) {
            $db->beginTransaction();
        }

        try {
            $success = $this->noticeModel->togglePin($id);

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
}
