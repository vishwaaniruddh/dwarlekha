<?php
namespace App\Services;

class CacheService {
    private static ?string $cacheDir = null;

    public static function getCacheDir(): string {
        if (self::$cacheDir === null) {
            $dir = __DIR__ . '/../../cache/';
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
                // Create .htaccess to deny direct web access
                @file_put_contents($dir . '.htaccess', "Deny from all\nOptions -Indexes\n");
            }
            self::$cacheDir = $dir;
        }
        return self::$cacheDir;
    }

    public static function setCacheDir(string $dir): void {
        self::$cacheDir = rtrim($dir, '/\\') . '/';
    }

    private static function getFilePath(string $key): string {
        $safeKey = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key);
        $hash = md5($key);
        return self::getCacheDir() . $safeKey . '_' . $hash . '.cache';
    }

    public static function get(string $key): mixed {
        $file = self::getFilePath($key);
        if (!file_exists($file)) {
            return null;
        }

        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }

        $entry = @unserialize($raw);
        if (!is_array($entry) || !isset($entry['expires_at']) || !array_key_exists('data', $entry)) {
            @unlink($file);
            return null;
        }

        // Check TTL expiration
        if (time() > $entry['expires_at']) {
            @unlink($file);
            return null;
        }

        return $entry['data'];
    }

    public static function set(string $key, mixed $data, int $ttlSeconds = 3600): bool {
        $file = self::getFilePath($key);
        $entry = [
            'key' => $key,
            'created_at' => time(),
            'expires_at' => time() + $ttlSeconds,
            'data' => $data
        ];

        $serialized = serialize($entry);
        return (bool)@file_put_contents($file, $serialized, LOCK_EX);
    }

    public static function remember(string $key, int $ttlSeconds, callable $callback): mixed {
        $cached = self::get($key);
        if ($cached !== null) {
            return $cached;
        }

        $freshData = $callback();
        self::set($key, $freshData, $ttlSeconds);
        return $freshData;
    }

    public static function forget(string $key): bool {
        $file = self::getFilePath($key);
        if (file_exists($file)) {
            return @unlink($file);
        }
        return true;
    }

    public static function flush(string $prefix = ''): int {
        $dir = self::getCacheDir();
        $files = glob($dir . '*' . '.cache');
        if (!$files) {
            return 0;
        }

        $cleared = 0;
        foreach ($files as $file) {
            if ($prefix === '' || str_starts_with(basename($file), $prefix)) {
                if (@unlink($file)) {
                    $cleared++;
                }
            }
        }
        return $cleared;
    }
}
