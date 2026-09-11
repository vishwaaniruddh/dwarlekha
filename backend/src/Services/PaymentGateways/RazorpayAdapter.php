<?php
namespace App\Services\PaymentGateways;

class RazorpayAdapter implements PaymentGatewayInterface {
    private string $keyId;
    private string $keySecret;
    private bool $testMode;

    public function __construct(?string $keyId = null, ?string $keySecret = null, bool $testMode = true) {
        $this->keyId = $keyId ?: (getenv('RAZORPAY_KEY_ID') ?: 'rzp_test_4gwWqpQ2mlWxfH');
        $this->keySecret = $keySecret ?: (getenv('RAZORPAY_KEY_SECRET') ?: 'e5DXo5IJdIkBO3apRU5zhCVd');
        $this->testMode = $testMode;
    }

    public function getProviderName(): string {
        return 'Razorpay';
    }

    public function getPublicKey(): string {
        return $this->keyId;
    }

    public function isTestMode(): bool {
        return $this->testMode;
    }

    public function createOrder(float $amount, string $receiptNumber, array $metadata = []): array {
        $amountInPaise = (int)round($amount * 100);
        $cleanReceipt = substr(preg_replace('/[^a-zA-Z0-9_-]/', '', $receiptNumber), 0, 40);

        $payload = [
            'amount' => $amountInPaise,
            'currency' => 'INR',
            'receipt' => $cleanReceipt,
            'payment_capture' => 1,
            'notes' => $metadata
        ];

        // Call Razorpay API
        $ch = curl_init('https://api.razorpay.com/v1/orders');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, "{$this->keyId}:{$this->keySecret}");
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError || $httpCode >= 400 || !$response) {
            // Development fallback simulator if test keys are offline
            if ($this->testMode) {
                return [
                    'provider' => 'Razorpay',
                    'order_id' => 'order_sim_' . uniqid(),
                    'amount' => $amount,
                    'amount_in_paise' => $amountInPaise,
                    'currency' => 'INR',
                    'key_id' => $this->keyId,
                    'receipt' => $cleanReceipt,
                    'is_simulation' => true
                ];
            }
            throw new \RuntimeException("Razorpay Order creation failed (HTTP $httpCode): " . ($response ?: $curlError));
        }

        $resData = json_decode($response, true);
        return [
            'provider' => 'Razorpay',
            'order_id' => $resData['id'] ?? ('order_' . uniqid()),
            'amount' => $amount,
            'amount_in_paise' => $amountInPaise,
            'currency' => 'INR',
            'key_id' => $this->keyId,
            'receipt' => $cleanReceipt,
            'is_simulation' => false
        ];
    }

    public function verifyPayment(array $payload): bool {
        $orderId = $payload['razorpay_order_id'] ?? ($payload['order_id'] ?? '');
        $paymentId = $payload['razorpay_payment_id'] ?? ($payload['payment_id'] ?? '');
        $signature = $payload['razorpay_signature'] ?? ($payload['signature'] ?? '');

        if (empty($orderId) || empty($paymentId) || empty($signature)) {
            return false;
        }

        $expectedSignature = hash_hmac('sha256', $orderId . "|" . $paymentId, $this->keySecret);
        return hash_equals($expectedSignature, $signature);
    }
}
