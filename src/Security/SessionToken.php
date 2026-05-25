<?php
declare(strict_types=1);

namespace RolepodWplabCompanion\Security;

/**
 * Per-session execution token. Issued by /handshake, required on /execute-php
 * (and v0.2+ /introspect for write-side calls). Stored via wp_cache_*
 * (in-memory per FastCGI worker) — survives within a single PHP-FPM process
 * but not across restarts. Acceptable security trade-off for v0.1.
 *
 * Token format: 32 hex chars prefixed with `wplab_sess_`.
 */
final class SessionToken
{
    private const CACHE_GROUP = 'rolepod_wplab_companion';
    private const TTL_SECONDS = 1800; // 30 min
    private const TOKEN_PREFIX = 'wplab_sess_';

    public static function issue(int $userId): string
    {
        $token = self::TOKEN_PREFIX . bin2hex(random_bytes(16));
        $payload = [
            'user_id' => $userId,
            'issued_at' => time(),
            'expires_at' => time() + self::TTL_SECONDS,
        ];
        wp_cache_set($token, $payload, self::CACHE_GROUP, self::TTL_SECONDS);
        return $token;
    }

    public static function verify(string $token, int $expectedUserId): bool
    {
        if (strpos($token, self::TOKEN_PREFIX) !== 0) {
            return false;
        }
        $payload = wp_cache_get($token, self::CACHE_GROUP);
        if (!is_array($payload)) {
            return false;
        }
        if (($payload['user_id'] ?? null) !== $expectedUserId) {
            return false;
        }
        if (($payload['expires_at'] ?? 0) < time()) {
            wp_cache_delete($token, self::CACHE_GROUP);
            return false;
        }
        return true;
    }

    public static function revoke(string $token): void
    {
        wp_cache_delete($token, self::CACHE_GROUP);
    }

    public static function ttlSeconds(): int
    {
        return self::TTL_SECONDS;
    }
}
