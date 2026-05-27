<?php
declare(strict_types=1);

namespace Rolepod\Wp\Abilities;

/**
 * Bridge to WordPress 7.0+ Abilities API.
 *
 * Registers a curated subset of Rolepod's safest capabilities as native
 * WP Abilities so:
 *
 *   - WP 7.0's built-in AI Client (Anthropic/OpenAI/Gemini providers shipped
 *     in core) can discover and invoke them directly from the WP admin
 *   - Third-party AI plugins that consume the Abilities registry get
 *     "Rolepod" tools for free without installing the rolepod-wplab MCP CLI
 *   - The standard /wp-json/wp-abilities/v1/* REST surface exposes them
 *     alongside existing /wp-json/wplab/v1/* endpoints (no duplication;
 *     each Ability internally calls the same domain code as its companion
 *     endpoint)
 *
 * Backward compatible: on WP < 7.0 (no `wp_register_ability()` function)
 * this class no-ops. Existing /wplab/v1/ endpoints stay unchanged regardless.
 */
final class Bridge
{
    public const NAMESPACE = 'rolepod';

    /**
     * @return string[] Registered ability IDs in load order.
     */
    public static function registered(): array
    {
        return [
            self::NAMESPACE . '/health-check',
            self::NAMESPACE . '/list-changes',
            self::NAMESPACE . '/panic-revert',
            self::NAMESPACE . '/recovery-status',
        ];
    }

    public static function isAvailable(): bool
    {
        return function_exists('wp_register_ability')
            && function_exists('wp_get_ability');
    }

    public static function init(): void
    {
        if (!self::isAvailable()) {
            return;
        }
        add_action('wp_abilities_api_init', [self::class, 'registerAll']);
    }

    public static function registerAll(): void
    {
        if (!self::isAvailable()) {
            return;
        }
        // WP 7.0 requires ability categories to be pre-registered. Built-in
        // categories are `site`, `user`, `woocommerce-rest`, `yoast-seo`.
        // We register `rolepod` so all our abilities share a clean,
        // namespaced grouping in any UI that surfaces categories.
        if (function_exists('wp_register_ability_category')) {
            wp_register_ability_category('rolepod', [
                'label'       => __('Rolepod', 'rolepod-wp'),
                'description' => __('Abilities exposed by the Rolepod for WordPress companion plugin.', 'rolepod-wp'),
            ]);
        }

        HealthCheckAbility::register();
        ListChangesAbility::register();
        PanicRevertAbility::register();
        RecoveryStatusAbility::register();
    }

    /**
     * Standard permission check applied to every Rolepod ability. The
     * Abilities API doesn't have a master toggle like the legacy
     * /wplab/v1/ endpoints used to; this caller-side check enforces
     * `manage_options` capability so abilities can only be invoked by
     * trusted administrators.
     */
    public static function adminPermission(): bool
    {
        return current_user_can('manage_options');
    }
}
