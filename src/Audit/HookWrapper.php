<?php
declare(strict_types=1);

namespace Rolepod\Wp\Audit;

/**
 * Wrapper helper for AI-issued hook callbacks.
 *
 * AI-generated hook callbacks (emitted by scaffold flows) wrap their body in:
 *
 *     function rolepod_wp_hook_<id>() {
 *         if (!\Rolepod\Wp\Audit\HookWrapper::isApplied('<audit_id>')) return;
 *         // ... actual logic ...
 *     }
 *
 * Toggling a hook row applied=0 in the ledger flips the wrapper short-circuit
 * — the hook still fires (registration is intact) but returns immediately.
 * Zero file change, zero reload, instant on/off.
 *
 * Per-request cache: we resolve the applied flag once per audit_id per request
 * to avoid hammering wp_options when many wrapped callbacks share the same hook.
 */
final class HookWrapper
{
    /** @var array<string,bool> */
    private static array $cache = [];

    public static function isApplied(string $auditId): bool
    {
        if (isset(self::$cache[$auditId])) {
            return self::$cache[$auditId];
        }

        global $wpdb;
        $table = ChangeLedger::tableName();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $applied = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT applied FROM {$table} WHERE audit_id = %s LIMIT 1",
            $auditId
        ));

        // If the row was deleted, default to applied=true to avoid silent breakage
        // when the user manually drops a row but the callback file still references it.
        if ($applied === 0 && $wpdb->last_error === '') {
            // Row exists, applied=0 → disabled.
            self::$cache[$auditId] = false;
            return false;
        }

        self::$cache[$auditId] = true;
        return true;
    }

    /**
     * Clear the per-request cache (used by tests + when the admin UI batch
     * toggles many rows in the same request).
     */
    public static function flushCache(): void
    {
        self::$cache = [];
    }
}
