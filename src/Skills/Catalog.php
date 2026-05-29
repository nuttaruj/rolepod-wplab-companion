<?php
declare(strict_types=1);

namespace Rolepod\Wp\Skills;

/**
 * Read side of the skill store: query the CPT and shape records for the agent.
 *
 * v1 has a single source (the user-authored CPT). We deliberately do NOT build
 * a pluggable source registry yet — there is exactly one source, so a registry
 * would be abstraction without a second implementation. If a built-in or
 * plugin-contributed source is ever needed, add it behind a filter then.
 *
 * Records are returned as structured arrays, never a pre-rendered markdown
 * blob — the MCP layer decides how to present them (the discovery surface
 * wants slug + description only; skill-get wants the full body). Keeping the
 * data structured is what makes progressive disclosure cheap.
 */
final class Catalog
{
    /**
     * Full record for one skill, by slug. `published` only — drafts never
     * reach an agent. Returns null when no published skill owns the slug.
     *
     * @return array{slug: string, name: string, description: string, content: string, enable_agentic: bool, enable_prompt: bool}|null
     */
    public static function find(string $slug): ?array
    {
        $slug = Parser::normalizeSlug($slug);
        if ($slug === '') {
            return null;
        }

        $posts = get_posts([
            'post_type' => Cpt::POST_TYPE,
            'post_status' => 'publish',
            'name' => $slug,
            'posts_per_page' => 1,
            'no_found_rows' => true,
        ]);

        $post = $posts[0] ?? null;
        return $post instanceof \WP_Post ? self::shape($post) : null;
    }

    /**
     * Every published skill, title-ordered.
     *
     * @return list<array{slug: string, name: string, description: string, content: string, enable_agentic: bool, enable_prompt: bool}>
     */
    public static function all(): array
    {
        $posts = get_posts([
            'post_type' => Cpt::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'no_found_rows' => true,
        ]);

        $out = [];
        foreach ($posts as $post) {
            if (!$post instanceof \WP_Post || $post->post_name === '') {
                continue;
            }
            $out[] = self::shape($post);
        }
        return $out;
    }

    /**
     * Skills eligible for discovery in a given mode. A skill must have a
     * non-empty description AND body, and the mode's activation flag on.
     *
     *   'agentic' → catalog discovery (default flag on)
     *   'prompt'  → invocable prompt (default flag off — opt-in)
     *
     * @param 'agentic'|'prompt' $mode
     * @return list<array{slug: string, name: string, description: string, content: string, enable_agentic: bool, enable_prompt: bool}>
     */
    public static function discoverable(string $mode): array
    {
        $key = $mode === 'prompt' ? 'enable_prompt' : 'enable_agentic';

        return array_values(array_filter(
            self::all(),
            static fn(array $s): bool =>
                trim($s['description']) !== ''
                && trim($s['content']) !== ''
                && $s[$key] === true
        ));
    }

    /**
     * Compact discovery view: slug + name + description + flags, NO body.
     * This is what the MCP catalog tool returns — bodies stay out until
     * skill-get pulls one on a description match.
     *
     * @return list<array{slug: string, name: string, description: string, enable_agentic: bool, enable_prompt: bool}>
     */
    public static function catalog(): array
    {
        return array_map(
            static fn(array $s): array => [
                'slug' => $s['slug'],
                'name' => $s['name'],
                'description' => $s['description'],
                'enable_agentic' => $s['enable_agentic'],
                'enable_prompt' => $s['enable_prompt'],
            ],
            self::discoverable('agentic')
        );
    }

    /**
     * @return array{slug: string, name: string, description: string, content: string, enable_agentic: bool, enable_prompt: bool}
     */
    private static function shape(\WP_Post $post): array
    {
        return [
            'slug' => $post->post_name,
            'name' => $post->post_title,
            'description' => $post->post_excerpt,
            'content' => $post->post_content,
            'enable_agentic' => (bool) get_post_meta($post->ID, Cpt::META_ENABLE_AGENTIC, true),
            'enable_prompt' => (bool) get_post_meta($post->ID, Cpt::META_ENABLE_PROMPT, true),
        ];
    }
}
