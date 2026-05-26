<?php
declare(strict_types=1);

namespace Rolepod\Wp\Security;

/**
 * Per-session execution token. Issued by /handshake, required on /execute-php
 * + /wp-cli + /fs-{read,write} + /php-session + /request-observer.
 *
 * Stored as a WP transient so the token survives across PHP-FPM workers +
 * across requests. v1.x stored via wp_cache_* which is per-request on hosts
 * without a persistent object cache (Redis/Memcached) — that broke the
 * handshake-then-act flow on shared hosting. Transients route through the
 * object cache when one is present, fall back to wp_options rows when not,
 * so the token persists regardless of host config.
 *
 * Token format: 32 hex chars prefixed with `wplab_sess_`. The token itself
 * is the transient name — 256-bit entropy makes guessing infeasible.
 */
final class SessionToken
{
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
        set_transient($token, $payload, self::TTL_SECONDS);
        return $token;
    }

    public static function verify(string $token, int $expectedUserId): bool
    {
        if (strpos($token, self::TOKEN_PREFIX) !== 0) {
            return false;
        }
        $payload = get_transient($token);
        if (!is_array($payload)) {
            return false;
        }
        if (($payload['user_id'] ?? null) !== $expectedUserId) {
            return false;
        }
        if (($payload['expires_at'] ?? 0) < time()) {
            delete_transient($token);
            return false;
        }
        return true;
    }

    public static function revoke(string $token): void
    {
        delete_transient($token);
    }

    public static function ttlSeconds(): int
    {
        return self::TTL_SECONDS;
    }
}
