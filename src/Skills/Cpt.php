<?php
declare(strict_types=1);

namespace Rolepod\Wp\Skills;

/**
 * Custom post type that stores site-owned agent skills.
 *
 * One skill = one post:
 *   post_title   → slug source (sanitized to the lookup slug)
 *   post_excerpt → one-line trigger description (the only field the agent
 *                  reads to decide whether to load the skill)
 *   post_content → SKILL.md body (instructions)
 *
 * Two activation flags live in post meta:
 *   _rolepod_skill_enable_agentic → appears in the agent catalog (default on)
 *   _rolepod_skill_enable_prompt  → exposed as an invocable prompt (default
 *                                   off; prompt-mode is a later phase)
 *
 * `revisions` is enabled so every save is recoverable through WP's native
 * history — agents iterate on skills, and a bad edit must be one click back.
 */
final class Cpt
{
    public const POST_TYPE = 'rolepod_wp_skill';

    public const META_ENABLE_AGENTIC = '_rolepod_skill_enable_agentic';
    public const META_ENABLE_PROMPT = '_rolepod_skill_enable_prompt';

    /** Keep this many revisions per skill — enough to recover from a bad agent edit. */
    private const REVISIONS_TO_KEEP = 15;

    public static function register(): void
    {
        register_post_type(self::POST_TYPE, [
            'label' => 'Rolepod Skills',
            'public' => false,
            'show_ui' => false,
            'show_in_rest' => false,
            'has_archive' => false,
            'rewrite' => false,
            'query_var' => false,
            'capability_type' => 'post',
            'map_meta_cap' => true,
            'supports' => ['title', 'editor', 'excerpt', 'revisions'],
        ]);

        $auth = static fn(): bool => current_user_can('manage_options');

        register_post_meta(self::POST_TYPE, self::META_ENABLE_AGENTIC, [
            'type' => 'boolean',
            'single' => true,
            'default' => true,
            'show_in_rest' => false,
            'auth_callback' => $auth,
        ]);

        register_post_meta(self::POST_TYPE, self::META_ENABLE_PROMPT, [
            'type' => 'boolean',
            'single' => true,
            'default' => false,
            'show_in_rest' => false,
            'auth_callback' => $auth,
        ]);

        add_filter(
            'wp_revisions_to_keep',
            static fn(int $num, \WP_Post $post): int =>
                $post->post_type === self::POST_TYPE ? self::REVISIONS_TO_KEEP : $num,
            10,
            2
        );
    }
}
