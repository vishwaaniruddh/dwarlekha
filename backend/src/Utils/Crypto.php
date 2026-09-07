<?php
namespace App\Utils;

class Crypto {
    private const CIPHER = 'AES-256-CBC';
    private const DEFAULT_KEY = 'SAR_SOCIETY_ENTERPRISE_SECRET_KEY_2026_SECURE_981247';

    private static function getKey(): string {
        $rawKey = getenv('APP_KEY') ?: self::DEFAULT_KEY;
        return hash('sha256', $rawKey, true); // 32 bytes binary
    }

    public static function encrypt(string $plainText): string {
        if ($plainText === '') {
            return '';
        }
        $key = self::getKey();
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $iv = openssl_random_pseudo_bytes($ivLength);

        $cipherRaw = openssl_encrypt($plainText, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);
        $hmac = hash_hmac('sha256', $iv . $cipherRaw, $key, true);

        return base64_encode($iv . $hmac . $cipherRaw);
    }

    public static function decrypt(string $cipherText): ?string {
        if ($cipherText === '') {
            return '';
        }
        $raw = base64_decode($cipherText, true);
        if ($raw === false) {
            return null;
        }

        $key = self::getKey();
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $hmacLength = 32;

        if (strlen($raw) < $ivLength + $hmacLength) {
            return null;
        }

        $iv = substr($raw, 0, $ivLength);
        $hmac = substr($raw, $ivLength, $hmacLength);
        $cipherRaw = substr($raw, $ivLength + $hmacLength);

        $calculatedHmac = hash_hmac('sha256', $iv . $cipherRaw, $key, true);
        if (!hash_equals($hmac, $calculatedHmac)) {
            return null; // Signature mismatch
        }

        $decrypted = openssl_decrypt($cipherRaw, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);
        return $decrypted === false ? null : $decrypted;
    }
}