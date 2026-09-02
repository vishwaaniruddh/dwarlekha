<?php
namespace App\Controllers;

use App\Config\TenantContext;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\FinancialReport;
use Exception;

class AccountingController extends BaseController {
    private ChartOfAccount $coaModel;
    private JournalEntry $journalModel;
    private FinancialReport $reportModel;

    public function __construct() {
        $this->coaModel = new ChartOfAccount();
        $this->journalModel = new JournalEntry();
        $this->reportModel = new FinancialReport();
    }

    public function chartOfAccounts(): void {
        $societyId = TenantContext::resolve();
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        if ($method === 'POST') {
            $input = $this->getJsonInput();
            $input['society_id'] = $societyId;
            try {
                $id = $this->coaModel->create($input);
                $this->success(['id' => $id], 'General ledger account created successfully', 201);
            } catch (Exception $e) {
                $this->error($e->getMessage(), 400);
            }
            return;
        }

        $accounts = $this->coaModel->getAllBySociety($societyId);
        $this->success($accounts);
    }

    public function journalEntries(): void {
        $societyId = TenantContext::resolve();
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        if ($method === 'POST') {
            $input = $this->getJsonInput();
            $entryData = [
                'society_id' => $societyId,
                'entry_date' => $input['entry_date'] ?? date('Y-m-d'),
                'narration' => $input['narration'] ?? '',
                'source_module' => 'Manual',
                'created_by_user_id' => null
            ];
            $items = $input['items'] ?? [];

            try {
                $id = $this->journalModel->createWithItems($entryData, $items);
                $this->success(['id' => $id], 'Journal entry posted successfully to General Ledger', 201);
            } catch (Exception $e) {
                $this->error($e->getMessage(), 400);
            }
            return;
        }

        $from = $_GET['from'] ?? null;
        $to = $_GET['to'] ?? null;
        $entries = $this->journalModel->getAllBySociety($societyId, $from, $to);
        $this->success($entries);
    }

    public function trialBalance(): void {
        $societyId = TenantContext::resolve();
        $from = $_GET['from'] ?? null;
        $to = $_GET['to'] ?? null;
        $tb = $this->reportModel->getTrialBalance($societyId, $from, $to);
        $this->success($tb);
    }

    public function pnl(): void {
        $societyId = TenantContext::resolve();
        $from = $_GET['from'] ?? null;
        $to = $_GET['to'] ?? null;
        $pnl = $this->reportModel->getProfitAndLoss($societyId, $from, $to);
        $this->success($pnl);
    }

    public function balanceSheet(): void {
        $societyId = TenantContext::resolve();
        $asOf = $_GET['as_of'] ?? date('Y-m-d');
        $bs = $this->reportModel->getBalanceSheet($societyId, $asOf);
        $this->success($bs);
    }

    public function residentLedger($unitIdentifier): void {
        $societyId = TenantContext::resolve();
        $ledger = $this->reportModel->getResidentLedger($societyId, $unitIdentifier);
        if (empty($ledger)) {
            $this->error('Resident ledger not found for unit', 404);
            return;
        }
        $this->success($ledger);
    }
}
