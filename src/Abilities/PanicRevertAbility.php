<?php
declare(strict_types=1);

namespace Rolepod\Wp\Abilities;

use Rolepod\Wp\Audit\ChangeRecorder;
use Rolepod\Wp\Audit\Toggler;
use Rolepod\Wp\Audit\HookWrapper;

/**
 * Ability: rolepod/panic-revert
 *
 * Disables every reversible change made in the last N minutes. Mirrors the
 * "Panic disable" button on the Change Ledger admin page. Destructive but
 * reversible row-by-row afterwards.
 *
 * Requires an explicit `confirm: true` flag in the input to prevent an AI
 * from calling it speculatively. WP 7.0's AI Client passes structured input
 * so this works as a soft second-opinion gate even when the assistant has
 * full execute permission.
 */
final class PanicRevertAbility
{
    public const ID = 'rolepod/panic-revert';

    public static function register(): void
    {
        wp_register_ability(
            self::ID,
            [
                'label'       => __('Rolepod panic revert', 'rolepod-wp'),
                'description' => __('Disable every reversible AI-issued change made in the last N minutes (1-1440). Requires explicit confirm:true. Destructive but row-by-row reversible.', 'rolepod-wp'),
                'category'    => 'content',
                'input_schema' => [
                    'type'       => 'object',
                    'required'   => ['confirm', 'minutes'],
                    'properties' => [
                        'minutes' => [
                            'type'        => 'integer',
                            'minimum'     => 1,
                            'maximum'     => 1440,
                            'description' => 'Revert window in minutes (1 = last minute, 1440 = last 24h).',
                        ],
                        'confirm' => [
                            'type'        => 'boolean',
                            'enum'        => [true],
                            'description' => 'Must be true. Explicit acknowledgement that the user wants this destructive action.',
                        ],
                    ],
                ],
                'output_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'disabled_count' => ['type' => 'integer'],
                        'window_minutes' => ['type' => 'integer'],
                        'ids'            => [
                            'type'  => 'array',
                            'items' => ['type' => 'integer'],
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
     * @return array<string, mixed>|\WP_Error
     */
    public static function execute(array $input = [])
    {
        if (empty($input['confirm'])) {
            return new \WP_Error(
                'rolepod_confirm_required',
                'panic-revert requires confirm:true to acknowledge the destructive action.',
                ['status' => 400]
            );
        }
        $minutes = (int) ($input['minutes'] ?? 0);
        if ($minutes < 1 || $minutes > 1440) {
            return new \WP_Error(
                'rolepod_invalid_window',
                'minutes must be between 1 and 1440.',
                ['status' => 400]
            );
        }

        $ids = ChangeRecorder::panic($minutes);
        foreach ($ids as $id) {
            $row = ChangeRecorder::getById((int) $id);
            if ($row !== null) {
                Toggler::apply($row, false);
            }
        }
        HookWrapper::flushCache();

        return [
            'disabled_count' => count($ids),
            'window_minutes' => $minutes,
            'ids'            => array_map('intval', $ids),
        ];
    }
}
