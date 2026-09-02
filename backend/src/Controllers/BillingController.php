<?php
namespace App\Controllers;

use App\Config\TenantContext;
use App\Models\Bill;
use App\Models\ChargeMaster;
use Exception;

class BillingController extends BaseController {
    private Bill $billModel;
    private ChargeMaster $chargeMasterModel;

    public function __construct() {
        $this->billModel = new Bill();
        $this->chargeMasterModel = new ChargeMaster();
    }

    public function index(): void {
        $societyId = TenantContext::resolve();
        $filters = [
            'unit_id' => $_GET['unit_id'] ?? null,
            'status' => $_GET['status'] ?? null,
            'from' => $_GET['from'] ?? null,
            'to' => $_GET['to'] ?? null,
        ];
        $bills = $this->billModel->getAllBySociety($societyId, $filters);
        
        // Return structured response with pagination if requested, else full list
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : null;
        $limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : null;

        if ($page !== null && $limit !== null) {
            $total = count($bills);
            $paginated = array_slice($bills, ($page - 1) * $limit, $limit);
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
        } else {
            $this->success($bills);
        }
    }

    public function show(int $id): void {
        $bill = $this->billModel->findByIdWithItems($id);
        if (!$bill) {
            $this->error('Bill not found', 404);
            return;
        }
        $this->success($bill);
    }

    public function generate(): void {
        $input = $this->getJsonInput();
        $db = \App\Config\Database::getConnection();

        $societyId = null;
        if (!empty($input['society_id'])) {
            $societyId = (int)$input['society_id'];
        } elseif (!empty($input['society_code'])) {
            $stmt = $db->prepare("SELECT id FROM societies WHERE society_code = ? AND is_deleted = 0 LIMIT 1");
            $stmt->execute([$input['society_code']]);
            $societyId = (int)$stmt->fetchColumn();
        }

        if ($societyId === null) {
            $societyId = TenantContext::resolve();
        }

        $periodStart = $input['billing_period_start'] ?? date('Y-m-01');
        $periodEnd = $input['billing_period_end'] ?? date('Y-m-t');
        $dueDate = $input['due_date'] ?? date('Y-m-15', strtotime('+1 month'));

        try {
            if ($societyId === 0) {
                // Global Superadmin: Generate bills across all active societies
                $socStmt = $db->query("SELECT id, name, society_code FROM societies WHERE is_deleted = 0 ORDER BY id ASC");
                $societies = $socStmt->fetchAll(\PDO::FETCH_ASSOC);

                $totalCount = 0;
                $totalAmount = 0.00;
                $allBills = [];

                foreach ($societies as $soc) {
                    $sId = (int)$soc['id'];
                    // Ensure society has charge masters before generating
                    $cmCount = (int)$db->query("SELECT COUNT(*) FROM charge_masters WHERE society_id = $sId AND is_recurring = 1 AND is_deleted = 0")->fetchColumn();
                    if ($cmCount > 0) {
                        $res = $this->billModel->generateMonthlyBills($sId, $periodStart, $periodEnd, $dueDate, null);
                        $totalCount += $res['count'];
                        $totalAmount += $res['total_amount'];
                        $allBills = array_merge($allBills, $res['bills']);
                    }
                }

                $this->success([
                    'count' => $totalCount,
                    'total_amount' => $totalAmount,
                    'bills' => $allBills
                ], "Successfully generated {$totalCount} monthly bills across all societies (Total: ₹{$totalAmount}).", 201);
            } else {
                $result = $this->billModel->generateMonthlyBills($societyId, $periodStart, $periodEnd, $dueDate, null);
                $this->success($result, "Successfully generated {$result['count']} monthly bills (Total: ₹{$result['total_amount']}).", 201);
            }
        } catch (Exception $e) {
            $this->error($e->getMessage(), 400);
        }
    }

    public function chargeMasters(): void {
        $societyId = TenantContext::resolve();
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        if ($method === 'POST') {
            $input = $this->getJsonInput();
            $input['society_id'] = $societyId;
            try {
                $id = $this->chargeMasterModel->create($input);
                $this->success(['id' => $id], 'Charge master rule created successfully', 201);
            } catch (Exception $e) {
                $this->error($e->getMessage(), 400);
            }
            return;
        }

        $masters = $this->chargeMasterModel->getAllBySociety($societyId);
        $this->success($masters);
    }

    public function summary(): void {
        $societyId = TenantContext::resolve();
        $bills = $this->billModel->getAllBySociety($societyId);

        $totalBilled = 0.00;
        $totalCollected = 0.00;
        $totalOutstanding = 0.00;
        $paidCount = 0;
        $pendingCount = 0;
        $overdueCount = 0;

        foreach ($bills as $b) {
            $totalBilled += (float)$b['total_amount'];
            $totalCollected += (float)$b['paid_amount'];
            $totalOutstanding += (float)$b['outstanding_amount'];

            if ($b['status'] === 'Paid') $paidCount++;
            elseif ($b['status'] === 'Overdue') $overdueCount++;
            else $pendingCount++;
        }

        $this->success([
            'total_bills' => count($bills),
            'total_billed' => round($totalBilled, 2),
            'total_collected' => round($totalCollected, 2),
            'total_outstanding' => round($totalOutstanding, 2),
            'collection_rate' => $totalBilled > 0 ? round(($totalCollected / $totalBilled) * 100, 1) : 0,
            'paid_count' => $paidCount,
            'pending_count' => $pendingCount,
            'overdue_count' => $overdueCount
        ]);
    }
}
