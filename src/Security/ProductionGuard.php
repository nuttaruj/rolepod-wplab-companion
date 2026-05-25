<?php
declare(strict_types=1);

namespace RolepodWplabCompanion\Security;

use RolepodWplabCompanion\Config;

/**
 * Production-host guard. Checks the WP siteurl against admin-configured glob
 * patterns. If matched, refuse power endpoints (execute-php, write-side
 * introspect) regardless of caller. No override exists — by design.
 */
final class ProductionGuard
{
    public static function isProduction(): bool
    {
        return self::matchedPattern() !== null;
    }

    public static function matchedPattern(): ?string
    {
        $siteurl = (string) get_option('siteurl');
        if ($siteurl === '') {
            return null;
        }
        $host = parse_url($siteurl, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }
        foreach (Config::productionHosts() as $pattern) {
            if (self::globMatch($pattern, $host)) {
                return $pattern;
            }
        }
        return null;
    }

    /**
     * Convert a glob pattern to a regex and test against the host.
     *
     *   "example.com"      → matches example.com
     *   "*.example.com"    → matches sub.example.com (and deeper)
     *   "client-*"         → matches client-anything
     */
    private static function globMatch(string $pattern, string $host): bool
    {
        $regex = '/^' . str_replace(['\\*', '\\.'], ['.*', '\\.'], preg_quote($pattern, '/')) . '$/i';
        return (bool) preg_match($regex, $host);
    }
}
