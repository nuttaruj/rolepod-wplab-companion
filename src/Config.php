<?php
declare(strict_types=1);

namespace RolepodWplabCompanion;

/**
 * Read/write companion config stored in wp_options::rolepod_wplab_companion_config.
 *
 * Shape:
 *   [
 *     'endpoints_enabled'   => bool,    // master toggle (Settings page)
 *     'execute_php_enabled' => bool,    // v0.1 stays false; v0.2 default true
 *     'production_hosts'    => string[], // glob patterns matched against siteurl
 *   ]
 */
final class Config
{
    private const OPTION = 'rolepod_wplab_companion_config';

    public static function all(): array
    {
        $raw = get_option(self::OPTION, []);
        return is_array($raw) ? $raw : [];
    }

    public static function endpointsEnabled(): bool
    {
        return (bool) (self::all()['endpoints_enabled'] ?? false);
    }

    public static function executePhpEnabled(): bool
    {
        return (bool) (self::all()['execute_php_enabled'] ?? false);
    }

    /** @return string[] */
    public static function productionHosts(): array
    {
        $hosts = self::all()['production_hosts'] ?? [];
        if (!is_array($hosts)) {
            return [];
        }
        return array_values(array_filter(array_map('strval', $hosts), 'strlen'));
    }

    public static function update(array $patch): void
    {
        $current = self::all();
        update_option(self::OPTION, array_merge($current, $patch));
    }
}
