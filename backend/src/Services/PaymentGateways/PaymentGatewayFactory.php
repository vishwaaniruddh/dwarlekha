<?php
namespace App\Services\PaymentGateways;

use App\Config\Database;
use PDO;

class PaymentGatewayFactory {
    /**
     * Resolve the active payment gateway for a given society tenant.
     * Falls back to Platform default Razorpay if no custom gateway is configured.
     */
    public static function getGateway(?int $societyId = null): PaymentGatewayInterface {
        if ($societyId !== null && $societyId > 0) {
            $db = Database::getConnection();
            $stmt = $db->prepare("
                SELECT provider, key_id, key_secret, merchant_id, webhook_secret, is_test_mode 
                FROM society_payment_gateways 
                WHERE society_id = ? AND is_active = 1 AND is_deleted = 0 
                ORDER BY id DESC LIMIT 1
            ");
            $stmt->execute([$societyId]);
            $config = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($config) {
                $provider = $config['provider'];
                $keyId = $config['key_id'];
                $keySecret = $config['key_secret'];
                $isTest = (bool)$config['is_test_mode'];

                switch ($provider) {
                    case 'Cashfree':
                        return new CashfreeAdapter($keyId, $keySecret, $isTest);
                    case 'PayU':
                        return new PayUAdapter($keyId, $keySecret, $isTest);
                    case 'Razorpay':
                    default:
                        return new RazorpayAdapter($keyId, $keySecret, $isTest);
                }
            }
        }

        // Global Platform Fallback (Razorpay)
        return new RazorpayAdapter(
            getenv('RAZORPAY_KEY_ID') ?: null,
            getenv('RAZORPAY_KEY_SECRET') ?: null,
            getenv('APP_ENV') !== 'production'
        );
    }

    /**
     * Get gateway by explicit provider name (useful for validation & tests)
     */
    public static function createByName(string $provider, string $keyId, string $keySecret, bool $isTest = true): PaymentGatewayInterface {
        switch ($provider) {
            case 'Cashfree':
                return new CashfreeAdapter($keyId, $keySecret, $isTest);
            case 'PayU':
                return new PayUAdapter($keyId, $keySecret, $isTest);
            case 'Razorpay':
            default:
                return new RazorpayAdapter($keyId, $keySecret, $isTest);
        }
    }
}
