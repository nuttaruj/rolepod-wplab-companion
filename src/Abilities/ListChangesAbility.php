<?php
declare(strict_types=1);

namespace Rolepod\Wp\Abilities;

use Rolepod\Wp\Audit\ChangeRecorder;

/**
 * Ability: rolepod/list-changes
 *
 * Returns recent rows from the AI Change Ledger. Useful for an AI to ask
 * "what writes happened on this site in the last hour?" before making more
 * edits, or to show the user a summary.
 *
 * Read-only; never modifies the ledger.
 */
final class ListChangesAbility
{
    public const ID = 'rolepod/list-changes';

    public static function register(): void
    {
        wp_register_ability(
            self::ID,
            [
                'label'       => __('List recent Rolepod changes', 'rolepod-wp'),
                'description' => __('Returns recent rows from the AI Change Ledger (writes the MCP issued through this site). Filter by category and limit.', 'rolepod-wp'),
                'category'    => 'content',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'limit' => [
                            'type'        => 'integer',
                            'minimum'     => 1,
                            'maximum'     => 200,
                            'default'     => 20,
                            'description' => 'Maximum rows to return (1-200).',
                        ],
                        'category' => [
                            'type'        => 'string',
                            'enum'        => ['hook', 'post', 'option', 'layout', 'file', 'plugin', 'theme', 'execute_php'],
                            'description' => 'Optional category filter.',
                        ],
                    ],
                ],
                'output_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'count' => ['type' => 'integer'],
                        'rows'  => [
                            'type'  => 'array',
                            'items' => [
                                'type'       => 'object',
                                'properties' => [
                                    'id'                => ['type' => 'integer'],
                                    'created_at'        => ['type' => 'string'],
                                    'category'          => ['type' => 'string'],
                                    'subcategory'       => ['type' => 'string'],
                                    'target_descriptor' => ['type' => 'string'],
                                    'source_tool'       => ['type' => 'string'],
                                    'applied'           => ['type' => 'boolean'],
                                    'reversible'        => ['type' => 'boolean'],
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
        $limit = (int) ($input['limit'] ?? 20);
        $limit = max(1, min(200, $limit));
        $filters = ['limit' => $limit];
        if (isset($input['category']) && is_string($input['category']) && $input['category'] !== '') {
            $filters['category'] = $input['category'];
        }

        $rows = ChangeRecorder::query($filters);
        $simplified = array_map(static function ($row): array {
            return [
                'id'                => (int) ($row['id'] ?? 0),
                'created_at'        => (string) ($row['created_at'] ?? ''),
                'category'          => (string) ($row['category'] ?? ''),
                'subcategory'       => (string) ($row['subcategory'] ?? ''),
                'target_descriptor' => (string) ($row['target_descriptor'] ?? ''),
                'source_tool'       => (string) ($row['source_tool'] ?? ''),
                'applied'           => (int) ($row['applied'] ?? 0) === 1,
                'reversible'        => (int) ($row['reversible'] ?? 0) === 1,
            ];
        }, $rows);

        return [
            'count' => count($simplified),
            'rows'  => $simplified,
        ];
    }
}
