<?php
namespace App\Services;

class RateLimiterService {
    private const DEFAULT_MAX_ATTEMPTS = 5;
    private const DEFAULT_DECAY_SECONDS = 900; // 15 minutes

    public static function resolveKey(string $email, ?string $ip = null): string {
        $cleanEmail = strtolower(trim($email));
        $clientIp = $ip ?: ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'));
        // If comma-separated forwarded IP, take the first client IP
        if (str_contains($clientIp, ',')) {
            $clientIp = trim(explode(',', $clientIp)[0]);
        }
        return 'rate_limit_login_' . md5($cleanEmail . '|' . $clientIp);
    }

    public static function tooManyAttempts(string $key, int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS): bool {
        $record = CacheService::get($key);
        if (!$record || !is_array($record)) {
            return false;
        }

        $attempts = (int)($record['attempts'] ?? 0);
        $lockedUntil = (int)($record['locked_until'] ?? 0);

        if ($lockedUntil > time()) {
            return true;
        }

        return $attempts >= $maxAttempts;
    }

    public static function hit(string $key, int $decaySeconds = self::DEFAULT_DECAY_SECONDS, int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS): int {
        $now = time();
        $record = CacheService::get($key);

        if (!$record || !is_array($record)) {
            $record = [
                'attempts' => 1,
                'created_at' => $now,
                'locked_until' => 0
            ];
            CacheService::set($key, $record, $decaySeconds);
            return 1;
        }

        $record['attempts'] = (int)($record['attempts'] ?? 0) + 1;

        if ($record['attempts'] >= $maxAttempts && empty($record['locked_until'])) {
            $record['locked_until'] = $now + $decaySeconds;
        }

        $remainingTtl = max(60, ($record['locked_until'] ?: ($record['created_at'] + $decaySeconds)) - $now);
        CacheService::set($key, $record, $remainingTtl);

        return $record['attempts'];
    }

    public static function availableIn(string $key): int {
        $record = CacheService::get($key);
        if (!$record || !is_array($record)) {
            return 0;
        }

        $now = time();
        $lockedUntil = (int)($record['locked_until'] ?? 0);
        if ($lockedUntil > $now) {
            return $lockedUntil - $now;
        }

        $createdAt = (int)($record['created_at'] ?? $now);
        $expiresAt = $createdAt + self::DEFAULT_DECAY_SECONDS;
        return max(0, $expiresAt - $now);
    }

    public static function clear(string $key): void {
        CacheService::forget($key);
    }
}
