<?php
namespace App\Controllers;

use App\Config\Database;
use App\Config\TenantContext;
use App\Config\RbacGuard;
use App\Services\PaymentGateways\PaymentGatewayFactory;
use PDO;
use Exception;

class PaymentGatewayConfigController extends BaseController {
    public function index(): void {
        $societyId = TenantContext::resolve();
        if (!empty($_GET['society_id'])) {
            $societyId = (int)$_GET['society_id'];
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT id, society_id, provider, key_id, merchant_id, is_active, is_test_mode, created_at 
            FROM society_payment_gateways 
            WHERE society_id = ? AND is_deleted = 0 
            ORDER BY is_active DESC, id DESC
        ");
        $stmt->execute([$societyId]);
        $configs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Current active gateway details
        $activeGateway = PaymentGatewayFactory::getGateway($societyId);

        $this->success([
            'society_id' => $societyId,
            'active_provider' => $activeGateway->getProviderName(),
            'active_public_key' => $activeGateway->getPublicKey(),
            'active_is_test' => $activeGateway->isTestMode(),
            'gateways' => $configs,
            'supported_providers' => ['Razorpay', 'Cashfree', 'PayU']
        ]);
    }

    public function save(): void {
        RbacGuard::requirePermission('settings.edit');
        $societyId = TenantContext::resolve();
        $input = $this->getJsonInput();

        if (!empty($input['society_id'])) {
            $societyId = (int)$input['society_id'];
        }

        if ($societyId <= 0) {
            $this->error('Valid Society ID is required to configure payment gateway.', 400);
            return;
        }

        $provider = $input['provider'] ?? 'Razorpay';
        if (!in_array($provider, ['Razorpay', 'Cashfree', 'PayU', 'PhonePe', 'Stripe'])) {
            $this->error("Unsupported payment provider '{$provider}'.", 400);
            return;
        }

        $keyId = trim($input['key_id'] ?? '');
        $keySecret = trim($input['key_secret'] ?? '');
        $merchantId = !empty($input['merchant_id']) ? trim($input['merchant_id']) : null;
        $webhookSecret = !empty($input['webhook_secret']) ? trim($input['webhook_secret']) : null;
        $isTestMode = isset($input['is_test_mode']) ? (int)$input['is_test_mode'] : 1;

        if (empty($keyId)) {
            $this->error('API Key ID / Client ID is required.', 400);
            return;
        }

        $db = Database::getConnection();
        $manageTx = !$db->inTransaction();
        if ($manageTx) $db->beginTransaction();

        try {
            // Check if existing config exists for this provider
            $checkStmt = $db->prepare("
                SELECT id, key_secret FROM society_payment_gateways 
                WHERE society_id = ? AND provider = ? AND is_deleted = 0 
                LIMIT 1
            ");
            $checkStmt->execute([$societyId, $provider]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

            // Deactivate all other gateways for this society
            $db->prepare("UPDATE society_payment_gateways SET is_active = 0 WHERE society_id = ?")->execute([$societyId]);

            if ($existing) {
                // If secret is blank or masked, keep old secret
                if (empty($keySecret) || str_contains($keySecret, '••••')) {
                    $keySecret = $existing['key_secret'];
                }

                $upStmt = $db->prepare("
                    UPDATE society_payment_gateways 
                    SET key_id = ?, key_secret = ?, merchant_id = ?, webhook_secret = ?, is_active = 1, is_test_mode = ? 
                    WHERE id = ?
                ");
                $upStmt->execute([$keyId, $keySecret, $merchantId, $webhookSecret, $isTestMode, $existing['id']]);
                $configId = (int)$existing['id'];
            } else {
                if (empty($keySecret)) {
                    throw new \InvalidArgumentException('API Secret / Secret Key is required for initial gateway setup.');
                }

                $insStmt = $db->prepare("
                    INSERT INTO society_payment_gateways 
                    (society_id, provider, key_id, key_secret, merchant_id, webhook_secret, is_active, is_test_mode) 
                    VALUES (?, ?, ?, ?, ?, ?, 1, ?)
                ");
                $insStmt->execute([$societyId, $provider, $keyId, $keySecret, $merchantId, $webhookSecret, $isTestMode]);
                $configId = (int)$db->lastInsertId();
            }

            if ($manageTx) $db->commit();

            $this->success([
                'id' => $configId,
                'society_id' => $societyId,
                'provider' => $provider,
                'key_id' => $keyId,
                'is_active' => 1,
                'is_test_mode' => (bool)$isTestMode
            ], "{$provider} gateway activated successfully for Society #{$societyId}.", 201);
        } catch (\Throwable $e) {
            if ($manageTx && $db->inTransaction()) $db->rollBack();
            $this->error($e->getMessage(), 400);
        }
    }

    public function test(): void {
        $input = $this->getJsonInput();
        $provider = $input['provider'] ?? 'Razorpay';
        $keyId = trim($input['key_id'] ?? '');
        $keySecret = trim($input['key_secret'] ?? '');

        if (empty($keyId)) {
            $this->error('API Key ID is required for testing.', 400);
            return;
        }

        try {
            $adapter = PaymentGatewayFactory::createByName($provider, $keyId, $keySecret, true);
            $testOrder = $adapter->createOrder(1.00, 'TEST_' . time(), [
                'resident_name' => 'Gateway Diagnostic Test',
                'resident_email' => 'admin@society.in'
            ]);

            $this->success([
                'provider' => $provider,
                'order_id' => $testOrder['order_id'],
                'verified' => true
            ], "Connection test for {$provider} was successful.");
        } catch (\Throwable $e) {
            $this->error("Connection test failed: " . $e->getMessage(), 400);
        }
    }

    public function delete(int $id): void {
        RbacGuard::requirePermission('settings.edit');
        $db = Database::getConnection();
        $stmt = $db->prepare("UPDATE society_payment_gateways SET is_deleted = 1, deleted_at = NOW(), is_active = 0 WHERE id = ?");
        $stmt->execute([$id]);
        $this->success(['id' => $id], 'Payment gateway configuration removed successfully.');
    }
}
