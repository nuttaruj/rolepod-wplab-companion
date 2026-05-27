<?php
declare(strict_types=1);

namespace Rolepod\Wp\Abilities;

use Rolepod\Wp\Config;
use Rolepod\Wp\Guardian;

/**
 * Ability: rolepod/health-check
 *
 * Returns a one-shot snapshot of plugin + companion + guardian + execute-php
 * state. Safe to call from any context — pure read, no side effects.
 *
 * Equivalent to the MCP tool `rolepod_wp_health_check` but consumable from
 * inside WordPress via the Abilities API.
 */
final class HealthCheckAbility
{
    public const ID = 'rolepod/health-check';

    public static function register(): void
    {
        wp_register_ability(
            self::ID,
            [
                'label'       => __('Rolepod health check', 'rolepod-wp'),
                'description' => __('Returns the plugin version, guardian status, and whether execute-php is enabled. Safe read-only diagnostic.', 'rolepod-wp'),
                'category'    => 'rolepod',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => new \stdClass(),
                ],
                'output_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'plugin_version'       => ['type' => 'string'],
                        'wp_version'           => ['type' => 'string'],
                        'php_version'          => ['type' => 'string'],
                        'siteurl'              => ['type' => 'string'],
                        'guardian_installed'   => ['type' => 'boolean'],
                        'execute_php_enabled'  => ['type' => 'boolean'],
                        'safe_mode'            => ['type' => 'boolean'],
                    ],
                ],
                'execute_callback'    => [self::class, 'execute'],
                'permission_callback' => [Bridge::class, 'adminPermission'],
                'meta' => [
                    'show_in_rest' => true,
                ],
            ]
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function execute(array $input = []): array
    {
        return [
            'plugin_version'      => defined('ROLEPOD_WP_VERSION') ? ROLEPOD_WP_VERSION : 'unknown',
            'wp_version'          => (string) get_bloginfo('version'),
            'php_version'         => PHP_VERSION,
            'siteurl'             => (string) get_site_url(),
            'guardian_installed'  => Guardian::isInstalled(),
            'execute_php_enabled' => (bool) Config::executePhpEnabled(),
            'safe_mode'           => (bool) get_option('rolepod_wp_safe_mode', false),
        ];
    }
}
