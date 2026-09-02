<?php
namespace App\Controllers;

use App\Services\VisitorService;
use Exception;

class VisitorController extends BaseController {
    private VisitorService $visitorService;

    public function __construct(?VisitorService $visitorService = null) {
        $this->visitorService = $visitorService ?: new VisitorService();
    }

    public function index(): void {
        $visitors = $this->visitorService->getVisitors();
        $this->success($visitors);
    }

    public function create(): void {
        $input = $this->getJsonInput();
        try {
            $pass = $this->visitorService->issuePass($input);
            $this->success($pass, 'Gate pass issued successfully', 201);
        } catch (Exception $e) {
            $this->error($e->getMessage(), 400);
        }
    }

    public function approve(string $visitorCode): void {
        $input = $this->getJsonInput();
        $approvedBy = $input['approvedBy'] ?? null;
        try {
            $this->visitorService->approvePass($visitorCode, $approvedBy);
            $this->success(['visitorCode' => $visitorCode, 'approvalStatus' => 'Approved'], "Pass {$visitorCode} approved by resident.");
        } catch (Exception $e) {
            $this->error($e->getMessage(), 400);
        }
    }

    public function deny(string $visitorCode): void {
        $input = $this->getJsonInput();
        $reason = $input['reason'] ?? null;
        try {
            $this->visitorService->denyPass($visitorCode, $reason);
            $this->success(['visitorCode' => $visitorCode, 'approvalStatus' => 'Denied'], "Pass {$visitorCode} denied by resident.");
        } catch (Exception $e) {
            $this->error($e->getMessage(), 400);
        }
    }

    public function admit(string $visitorCode): void {
        try {
            $this->visitorService->allowInside($visitorCode);
            $this->success(['visitorCode' => $visitorCode, 'status' => 'Inside'], "Visitor {$visitorCode} authorized and admitted onto premises.");
        } catch (Exception $e) {
            $this->error($e->getMessage(), 400);
        }
    }

    public function checkout(string $visitorCode): void {
        try {
            $this->visitorService->checkout($visitorCode);
            $this->success(null, "Visitor {$visitorCode} checked out.");
        } catch (Exception $e) {
            $this->error($e->getMessage(), 400);
        }
    }

    public function validate(): void {
        $input = $this->getJsonInput();
        $passCode = $input['passCode'] ?? ($_GET['passCode'] ?? ($_GET['code'] ?? null));
        if (empty($passCode)) {
            $this->error("Pass code is required.", 400);
            return;
        }
        try {
            $pass = $this->visitorService->validatePass($passCode);
            $this->success($pass, "Gate pass verified.");
        } catch (Exception $e) {
            $this->error($e->getMessage(), 404);
        }
    }
}
