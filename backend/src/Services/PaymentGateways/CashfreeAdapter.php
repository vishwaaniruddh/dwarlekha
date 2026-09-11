<?php
namespace App\Services\PaymentGateways;

class CashfreeAdapter implements PaymentGatewayInterface {
    private string $appId;
    private string $secretKey;
    private bool $testMode;

    public function __construct(string $appId, string $secretKey, bool $testMode = true) {
        $this->appId = $appId;
        $this->secretKey = $secretKey;
        $this->testMode = $testMode;
    }

    public function getProviderName(): string {
        return 'Cashfree';
    }

    public function getPublicKey(): string {
        return $this->appId;
    }

    public function isTestMode(): bool {
        return $this->testMode;
    }

    private function getBaseUrl(): string {
        return $this->testMode 
            ? 'https://sandbox.cashfree.com/pg' 
            : 'https://api.cashfree.com/pg';
    }

    public function createOrder(float $amount, string $receiptNumber, array $metadata = []): array {
        $orderId = 'CF_' . substr(preg_replace('/[^a-zA-Z0-9_-]/', '', $receiptNumber), 0, 30) . '_' . time();

        $payload = [
            'order_id' => $orderId,
            'order_amount' => round($amount, 2),
            'order_currency' => 'INR',
            'customer_details' => [
                'customer_id' => !empty($metadata['resident_id']) ? 'RES_' . $metadata['resident_id'] : 'CUST_' . time(),
                'customer_email' => $metadata['resident_email'] ?? 'resident@society.in',
                'customer_phone' => preg_replace('/[^0-9]/', '', $metadata['resident_phone'] ?? '9999999999') ?: '9999999999',
                'customer_name' => $metadata['resident_name'] ?? 'Resident'
            ],
            'order_meta' => [
                'return_url' => $metadata['return_url'] ?? 'http://localhost/society-management/?tab=billing&cf_order_id={order_id}',
                'notify_url' => $metadata['webhook_url'] ?? null
            ],
            'order_note' => "Maintenance payment for {$receiptNumber}"
        ];

        $ch = curl_init($this->getBaseUrl() . '/orders');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'x-client-id: ' . $this->appId,
            'x-client-secret: ' . $this->secretKey,
            'x-api-version: 2023-08-01'
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError || $httpCode >= 400 || !$response) {
            // Simulator in test mode
            if ($this->testMode) {
                return [
                    'provider' => 'Cashfree',
                    'order_id' => $orderId,
                    'payment_session_id' => 'session_cf_sim_' . uniqid(),
                    'amount' => $amount,
                    'currency' => 'INR',
                    'key_id' => $this->appId,
                    'is_simulation' => true
                ];
            }
            throw new \RuntimeException("Cashfree Order creation failed (HTTP $httpCode): " . ($response ?: $curlError));
        }

        $resData = json_decode($response, true);
        return [
            'provider' => 'Cashfree',
            'order_id' => $resData['order_id'] ?? $orderId,
            'payment_session_id' => $resData['payment_session_id'] ?? null,
            'amount' => $amount,
            'currency' => 'INR',
            'key_id' => $this->appId,
            'is_simulation' => false
        ];
    }

    public function verifyPayment(array $payload): bool {
        // Option A: Verify signature if raw webhook signature is provided
        if (!empty($payload['signature']) && !empty($payload['raw_body']) && !empty($payload['timestamp'])) {
            $dataToSign = $payload['timestamp'] . $payload['raw_body'];
            $expectedSignature = base64_encode(hash_hmac('sha256', $dataToSign, $this->secretKey, true));
            return hash_equals($expectedSignature, $payload['signature']);
        }

        // Option B: Verify order status via Cashfree Order API directly
        $orderId = $payload['order_id'] ?? ($payload['cf_order_id'] ?? ($payload['gateway_order_id'] ?? null));
        if (empty($orderId)) {
            return false;
        }

        // In test mode simulation
        if (str_starts_with($orderId, 'CF_') && !empty($payload['is_simulation'])) {
            return true;
        }

        $ch = curl_init($this->getBaseUrl() . '/orders/' . urlencode($orderId));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'x-client-id: ' . $this->appId,
            'x-client-secret: ' . $this->secretKey,
            'x-api-version: 2023-08-01'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            return isset($data['order_status']) && ($data['order_status'] === 'PAID');
        }

        return false;
    }
}
