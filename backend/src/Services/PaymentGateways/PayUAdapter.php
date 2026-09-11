<?php
namespace App\Services\PaymentGateways;

class PayUAdapter implements PaymentGatewayInterface {
    private string $merchantKey;
    private string $merchantSalt;
    private bool $testMode;

    public function __construct(string $merchantKey, string $merchantSalt, bool $testMode = true) {
        $this->merchantKey = $merchantKey;
        $this->merchantSalt = $merchantSalt;
        $this->testMode = $testMode;
    }

    public function getProviderName(): string {
        return 'PayU';
    }

    public function getPublicKey(): string {
        return $this->merchantKey;
    }

    public function isTestMode(): bool {
        return $this->testMode;
    }

    public function getCheckoutUrl(): string {
        return $this->testMode 
            ? 'https://test.payu.in/_payment' 
            : 'https://secure.payu.in/_payment';
    }

    public function createOrder(float $amount, string $receiptNumber, array $metadata = []): array {
        $txnId = 'PAYU_' . substr(preg_replace('/[^a-zA-Z0-9_-]/', '', $receiptNumber), 0, 20) . '_' . time();
        $formattedAmount = sprintf("%.2f", $amount);
        $productInfo = "Maintenance Payment {$receiptNumber}";
        $firstname = $metadata['resident_name'] ?? 'Resident';
        $email = $metadata['resident_email'] ?? 'resident@society.in';
        $phone = preg_replace('/[^0-9]/', '', $metadata['resident_phone'] ?? '9999999999') ?: '9999999999';

        // PayU Golden Hash Formula:
        // sha512(key|txnid|amount|productinfo|firstname|email|udf1|udf2|udf3|udf4|udf5||||||salt)
        $hashString = "{$this->merchantKey}|{$txnId}|{$formattedAmount}|{$productInfo}|{$firstname}|{$email}|||||||||||{$this->merchantSalt}";
        $hash = strtolower(hash('sha512', $hashString));

        return [
            'provider' => 'PayU',
            'order_id' => $txnId,
            'amount' => (float)$formattedAmount,
            'currency' => 'INR',
            'key_id' => $this->merchantKey,
            'hash' => $hash,
            'productinfo' => $productInfo,
            'firstname' => $firstname,
            'email' => $email,
            'phone' => $phone,
            'action_url' => $this->getCheckoutUrl(),
            'is_simulation' => false
        ];
    }

    public function verifyPayment(array $payload): bool {
        $status = $payload['status'] ?? '';
        $txnId = $payload['txnid'] ?? '';
        $amount = isset($payload['amount']) ? sprintf("%.2f", (float)$payload['amount']) : '';
        $productInfo = $payload['productinfo'] ?? '';
        $firstname = $payload['firstname'] ?? '';
        $email = $payload['email'] ?? '';
        $postedHash = strtolower($payload['hash'] ?? '');

        if ($status !== 'success' && $status !== 'Success') {
            return false;
        }

        // Reverse hash verification formula:
        // sha512(salt|status||||||udf5|udf4|udf3|udf2|udf1|email|firstname|productinfo|amount|txnid|key)
        $reverseString = "{$this->merchantSalt}|{$status}|||||||||||{$email}|{$firstname}|{$productInfo}|{$amount}|{$txnId}|{$this->merchantKey}";
        $expectedHash = strtolower(hash('sha512', $reverseString));

        if (hash_equals($expectedHash, $postedHash)) {
            return true;
        }

        // Check with additionalCharges if discount/surcharge was applied by bank
        if (!empty($payload['additionalCharges'])) {
            $addlString = "{$payload['additionalCharges']}|{$this->merchantSalt}|{$status}|||||||||||{$email}|{$firstname}|{$productInfo}|{$amount}|{$txnId}|{$this->merchantKey}";
            $expectedAddlHash = strtolower(hash('sha512', $addlString));
            return hash_equals($expectedAddlHash, $postedHash);
        }

        return false;
    }
}
