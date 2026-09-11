<?php
namespace App\Services\PaymentGateways;

interface PaymentGatewayInterface {
    public function getProviderName(): string;
    public function getPublicKey(): string;
    public function isTestMode(): bool;
    
    /**
     * Create an order on the gateway provider.
     * Returns an array with unified keys: order_id, amount, currency, checkout_data
     */
    public function createOrder(float $amount, string $receiptNumber, array $metadata = []): array;

    /**
     * Verify payment authenticity via cryptographic hash / HMAC signature.
     */
    public function verifyPayment(array $payload): bool;
}
