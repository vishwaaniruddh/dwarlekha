<?php
require_once __DIR__ . '/../vendor/autoload.php';

use App\Config\Database;

try {
    $db = Database::getConnection();
    $sql = "
    CREATE TABLE IF NOT EXISTS `society_payment_gateways` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `society_id` INT NOT NULL,
      `provider` ENUM('Razorpay', 'Cashfree', 'PayU', 'PhonePe', 'Stripe') NOT NULL DEFAULT 'Razorpay',
      `key_id` VARCHAR(255) NOT NULL,
      `key_secret` VARCHAR(255) NOT NULL,
      `merchant_id` VARCHAR(255) NULL,
      `webhook_secret` VARCHAR(255) NULL,
      `is_active` TINYINT(1) NOT NULL DEFAULT 1,
      `is_test_mode` TINYINT(1) NOT NULL DEFAULT 1,
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
      `deleted_at` TIMESTAMP NULL DEFAULT NULL,
      INDEX `idx_society_gateway` (`society_id`, `is_active`, `is_deleted`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $db->exec($sql);
    echo "Table `society_payment_gateways` created successfully.\n";
} catch (\Throwable $e) {
    echo "Error creating table: " . $e->getMessage() . "\n";
    exit(1);
}
