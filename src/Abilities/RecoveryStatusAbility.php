<?php
declare(strict_types=1);

namespace Rolepod\Wp\Abilities;

use Rolepod\Wp\Guardian;

/**
 * Ability: rolepod/recovery-status
 *
 * Returns recovery guardian status + recent fatals + safe-mode flag.
 * Designed for an AI assistant to ask "is this site broken?" before
 * proposing fixes — and for a human to ask "what crashed?" in plain
 * language.
 */
final class RecoveryStatusAbility
{
    public const ID = 'rolepod/recovery-status';

    public static function register(): void
    {
        wp_register_ability(
            self::ID,
            [
                'label'       => __('Rolepod recovery status', 'rolepod-wp'),
                'description' => __('Returns mu-plugin guardian status, the last few PHP fatals caught by it, and whether safe-mode is on.', 'rolepod-wp'),
                'category'    => 'rolepod',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => new \stdClass(),
                ],
                'output_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'guardian_installed' => ['type' => 'boolean'],
                        'guardian_path'      => ['type' => 'string'],
                        'safe_mode'          => ['type' => 'boolean'],
                        'recent_fatals'      => [
                            'type'  => 'array',
                            'items' => [
                                'type'       => 'object',
                                'properties' => [
                                    'file'        => ['type' => 'string'],
                                    'line'        => ['type' => 'integer'],
                                    'message'     => ['type' => 'string'],
                                    'ts'          => ['type' => 'integer'],
                                    'request_uri' => ['type' => 'string'],
                                ],
                            ],
                        ],
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
        $recent = get_transient('rolepod_wp_recovery_recent_fatals');
        $fatals = is_array($recent) ? array_values(array_reverse($recent)) : [];
        $simplified = array_map(static function ($f): array {
            return [
                'file'        => (string) ($f['file'] ?? ''),
                'line'        => (int) ($f['line'] ?? 0),
                'message'     => (string) ($f['message'] ?? ''),
                'ts'          => (int) ($f['ts'] ?? 0),
                'request_uri' => (string) ($f['request_uri'] ?? ''),
            ];
        }, $fatals);

        return [
            'guardian_installed' => Guardian::isInstalled(),
            'guardian_path'      => Guardian::destinationPath(),
            'safe_mode'          => (bool) get_option('rolepod_wp_safe_mode', false),
            'recent_fatals'      => $simplified,
        ];
    }
}
