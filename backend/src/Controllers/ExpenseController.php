<?php
namespace App\Controllers;

use App\Config\TenantContext;
use App\Models\Expense;
use App\Models\Vendor;
use Exception;

class ExpenseController extends BaseController {
    private Expense $expenseModel;
    private Vendor $vendorModel;

    public function __construct() {
        $this->expenseModel = new Expense();
        $this->vendorModel = new Vendor();
    }

    public function index(): void {
        $societyId = TenantContext::resolve();
        $filters = [
            'approval_status' => $_GET['approval_status'] ?? null,
            'payment_status' => $_GET['payment_status'] ?? null,
            'from' => $_GET['from'] ?? null,
            'to' => $_GET['to'] ?? null,
        ];
        $expenses = $this->expenseModel->getAllBySociety($societyId, $filters);

        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 15;
        $total = count($expenses);
        $paginated = array_slice($expenses, ($page - 1) * $limit, $limit);

        $this->success([
            'data' => $paginated,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'total_pages' => ceil($total / $limit) ?: 1,
                'has_next' => ($page * $limit) < $total,
                'has_prev' => $page > 1
            ]
        ]);
    }

    public function create(): void {
        $societyId = TenantContext::resolve();
        $input = $this->getJsonInput();
        $input['society_id'] = $societyId;

        try {
            $result = $this->expenseModel->createExpense($input);
            $this->success($result, "Expense voucher {$result['voucher_number']} submitted for approval.", 201);
        } catch (Exception $e) {
            $this->error($e->getMessage(), 400);
        }
    }

    public function approve(int $id): void {
        $input = $this->getJsonInput();
        $userId = $input['user_id'] ?? 1;

        try {
            $this->expenseModel->approveExpense($id, $userId);
            $this->success(null, 'Expense voucher approved and posted to Accounts Payable.');
        } catch (Exception $e) {
            $this->error($e->getMessage(), 400);
        }
    }

    public function pay(int $id): void {
        $input = $this->getJsonInput();
        $mode = $input['payment_mode'] ?? 'Bank_Transfer';
        $userId = $input['user_id'] ?? 1;

        try {
            $this->expenseModel->payExpense($id, $mode, $userId);
            $this->success(null, "Vendor payment processed successfully via {$mode}.");
        } catch (Exception $e) {
            $this->error($e->getMessage(), 400);
        }
    }

    public function vendors(): void {
        $societyId = TenantContext::resolve();
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        if ($method === 'POST') {
            $input = $this->getJsonInput();
            $input['society_id'] = $societyId;
            try {
                $id = $this->vendorModel->create($input);
                $this->success(['id' => $id], 'Vendor registered successfully', 201);
            } catch (Exception $e) {
                $this->error($e->getMessage(), 400);
            }
            return;
        }

        $vendors = $this->vendorModel->getAllBySociety($societyId);
        $this->success($vendors);
    }
}
