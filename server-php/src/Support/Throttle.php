<?php

declare(strict_types=1);

namespace Aicountly\Api\Support;

use Aicountly\Api\Env;

/**
 * Two small guards kept on this host's own disk, because a cPanel PHP pool has no shared memory
 * cache to lean on and the database is the wrong place for per-request noise.
 *
 *  - remembered(): a marker that lives for a few seconds — "the portal already refused this
 *    session key". Every authenticated request asks the portal (2 s to connect, 6 s in all); a
 *    client replaying a dead key would otherwise make the portal answer the same "no" again and
 *    again, each time holding a PHP worker.
 *  - allow(): a fixed-window counter per bucket — "at most N of these per minute from one client".
 *
 * Both fail OPEN on a disk problem: a broken cache directory must never sign a person out or
 * refuse a request that would otherwise succeed. They only ever save work, never grant access.
 * The directory is PURCHASE_CACHE_DIR, else a per-install folder under the system temp dir; markers
 * hold no secret (file names are SHA-256 of the key) and no request content.
 */
final class Throttle
{
    public static function directory(): string
    {
        $configured = trim(Env::get('PURCHASE_CACHE_DIR'));
        $dir = $configured !== '' ? rtrim($configured, '/') : sys_get_temp_dir() . '/purchase-cache-' . substr(hash('sha256', __DIR__), 0, 12);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        return $dir;
    }

    /** Remember $key for $seconds (0 or less: do nothing). */
    public static function remember(string $namespace, string $key, int $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }
        @file_put_contents(self::path($namespace, $key), (string) (time() + $seconds), LOCK_EX);
        if (random_int(1, 200) === 1) {
            self::sweep($namespace, $seconds);
        }
    }

    /** Whether $key was remembered and has not expired. */
    public static function remembered(string $namespace, string $key): bool
    {
        $file = self::path($namespace, $key);
        $until = @file_get_contents($file);
        if ($until === false) {
            return false;
        }
        if ((int) $until > time()) {
            return true;
        }
        @unlink($file);

        return false;
    }

    /**
     * Count one event in $bucket for this minute; false once more than $limit have been counted.
     * A limit of 0 or less disables the check.
     */
    public static function allow(string $namespace, string $bucket, int $limit, int $windowSeconds = 60): bool
    {
        if ($limit <= 0) {
            return true;
        }
        $window = intdiv(time(), max(1, $windowSeconds));
        $file = self::path($namespace, $bucket . '|' . $window);
        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            return true;
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                return true;
            }
            $count = (int) stream_get_contents($handle) + 1;
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) $count);
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
        // Yesterday's windows are litter; sweep now and then rather than on every call.
        if (random_int(1, 200) === 1) {
            self::sweep($namespace, $windowSeconds);
        }

        return $count <= $limit;
    }

    private static function path(string $namespace, string $key): string
    {
        return self::directory() . '/' . preg_replace('/[^a-z0-9_-]/', '', strtolower($namespace)) . '-' . hash('sha256', $key);
    }

    private static function sweep(string $namespace, int $windowSeconds): void
    {
        $cutoff = time() - 2 * max(60, $windowSeconds);
        foreach (glob(self::directory() . '/' . preg_replace('/[^a-z0-9_-]/', '', strtolower($namespace)) . '-*') ?: [] as $file) {
            if (@filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }
}
