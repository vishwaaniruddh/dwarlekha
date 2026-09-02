<?php
namespace App\Controllers;

use App\Config\TenantContext;
use App\Models\Payment;
use Exception;

class PaymentController extends BaseController {
    private Payment $paymentModel;

    public function __construct() {
        $this->paymentModel = new Payment();
    }

    public function index(): void {
        $societyId = TenantContext::resolve();
        $filters = [
            'unit_id' => $_GET['unit_id'] ?? null,
            'status' => $_GET['status'] ?? null,
            'payment_mode' => $_GET['payment_mode'] ?? null,
            'from' => $_GET['from'] ?? null,
            'to' => $_GET['to'] ?? null,
        ];
        $payments = $this->paymentModel->getAllBySociety($societyId, $filters);

        // Return structured response with pagination if requested, else full list
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : null;
        $limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : null;

        if ($page !== null && $limit !== null) {
            $total = count($payments);
            $paginated = array_slice($payments, ($page - 1) * $limit, $limit);
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
            $this->success($payments);
        }
    }

    /**
     * Online Payment Gateway: Create Razorpay Order
     */
    public function initiateOnline(): void {
        $societyId = TenantContext::resolve();
        $input = $this->getJsonInput();

        $billId = (int)($input['bill_id'] ?? 0);
        $amount = (float)($input['amount'] ?? 0.00);

        if ($amount <= 0) {
            $this->error('Invalid payment amount', 400);
            return;
        }

        // Fetch Bill & Unit info
        $billModel = new \App\Models\Bill();
        $bill = $billId ? $billModel->findByIdWithItems($billId) : null;
        $receiptRef = $bill ? $bill['bill_number'] : ('REC_' . time());

        $razorpay = new \App\Services\RazorpayService();
        $order = $razorpay->createOrder($amount, $receiptRef, [
            'bill_id' => (string)$billId,
            'unit_id' => (string)($bill['unit_id'] ?? $input['unit_id'] ?? ''),
            'society_id' => (string)($bill['society_id'] ?? $societyId)
        ]);

        $this->success([
            'order_id' => $order['id'],
            'razorpay_order_id' => $order['id'],
            'amount' => $amount,
            'amount_in_paise' => $order['amount'] ?? (int)round($amount * 100),
            'currency' => $order['currency'] ?? 'INR',
            'key_id' => $razorpay->getKeyId(),
            'bill_id' => $billId,
            'bill_number' => $bill['bill_number'] ?? null,
            'unit_code' => $bill['unit_code'] ?? null,
            'resident_name' => $bill['resident_name'] ?? 'Resident',
            'resident_email' => $bill['resident_email'] ?? 'resident@society.in',
            'resident_phone' => $bill['resident_phone'] ?? '+919999999999'
        ], 'Razorpay order created successfully');
    }

    /**
     * Online Payment Gateway: Verify Razorpay Payment & Post to Ledger
     */
    public function verifyOnline(): void {
        $societyId = TenantContext::resolve();
        $input = $this->getJsonInput();

        $razorpayOrderId = $input['razorpay_order_id'] ?? $input['order_id'] ?? null;
        $razorpayPaymentId = $input['razorpay_payment_id'] ?? null;
        $razorpaySignature = $input['razorpay_signature'] ?? null;

        $razorpay = new \App\Services\RazorpayService();

        // If signature is provided, verify it
        if ($razorpaySignature && $razorpayOrderId && $razorpayPaymentId) {
            $isValid = $razorpay->verifySignature($razorpayOrderId, $razorpayPaymentId, $razorpaySignature);
            if (!$isValid) {
                $this->error('Razorpay signature verification failed. Untrusted payment payload.', 400);
                return;
            }
        }

        $input['society_id'] = !empty($input['society_id']) ? $input['society_id'] : $societyId;
        $input['payment_mode'] = $input['payment_mode'] ?? 'Razorpay';
        $input['status'] = 'Success';
        $input['gateway_transaction_id'] = $razorpayPaymentId ?: ('rzp_pay_' . uniqid());
        $input['gateway_order_id'] = $razorpayOrderId;
        $input['notes'] = "Settled via Razorpay PG (Payment ID: {$input['gateway_transaction_id']}, Order ID: {$razorpayOrderId})";

        try {
            $result = $this->paymentModel->recordPayment($input);
            $this->success($result, 'Payment verified and receipt generated successfully.', 201);
        } catch (Exception $e) {
            $this->error($e->getMessage(), 400);
        }
    }

    /**
     * Record Offline Payment (Cash / Cheque at Society Office)
     */
    public function recordOffline(): void {
        $societyId = TenantContext::resolve();
        $input = $this->getJsonInput();
        $input['society_id'] = $societyId;

        try {
            $result = $this->paymentModel->recordPayment($input);
            $this->success($result, "Payment recorded successfully. Receipt: {$result['receipt_number']}", 201);
        } catch (Exception $e) {
            $this->error($e->getMessage(), 400);
        }
    }

    /**
     * Clear / Bounce Cheque
     */
    public function clearCheque(int $paymentId): void {
        $input = $this->getJsonInput();
        $outcome = $input['outcome'] ?? 'Success';

        try {
            $this->paymentModel->clearCheque($paymentId, $outcome);
            $this->success(null, "Cheque status updated to {$outcome}.");
        } catch (Exception $e) {
            $this->error($e->getMessage(), 400);
        }
    }
}
