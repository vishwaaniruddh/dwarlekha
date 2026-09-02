<?php
namespace App\Controllers;

use App\Services\NoticeService;
use App\Config\TenantContext;
use Exception;

class NoticeController extends BaseController {
    private NoticeService $noticeService;

    public function __construct(?NoticeService $noticeService = null) {
        $this->noticeService = $noticeService ?: new NoticeService();
    }

    public function index(): void {
        $societyId = TenantContext::getSocietyId();
        if (isset($_GET['society_id'])) {
            $societyId = (int)$_GET['society_id'];
        }
        $notices = $this->noticeService->getNotices($societyId);
        $this->success($notices);
    }

    public function show(string $id): void {
        $notice = $this->noticeService->getNoticeById($id);
        if (!$notice) {
            $this->error('Notice not found', 404);
            return;
        }
        $this->success($notice);
    }

    public function create(): void {
        $input = $this->getJsonInput();
        try {
            $notice = $this->noticeService->broadcast($input);
            $this->success($notice, 'Notice broadcasted successfully', 201);
        } catch (Exception $e) {
            $this->error($e->getMessage(), 400);
        }
    }

    public function update(string $id): void {
        $input = $this->getJsonInput();
        try {
            $notice = $this->noticeService->updateNotice($id, $input);
            $this->success($notice, 'Notice updated successfully');
        } catch (Exception $e) {
            $this->error($e->getMessage(), 400);
        }
    }

    public function delete(string $id): void {
        try {
            $success = $this->noticeService->deleteNotice($id);
            if ($success) {
                $this->success(null, 'Notice deleted successfully');
            } else {
                $this->error('Failed to delete notice or already deleted', 404);
            }
        } catch (Exception $e) {
            $this->error($e->getMessage(), 400);
        }
    }

    public function togglePin(string $id): void {
        try {
            $success = $this->noticeService->togglePin($id);
            $this->success(['pinned' => $success], 'Notice pin status updated');
        } catch (Exception $e) {
            $this->error($e->getMessage(), 400);
        }
    }
}
