<?php
declare(strict_types=1);

namespace Rolepod\Wp\Endpoint;

use Rolepod\Wp\Audit\Log;
use Rolepod\Wp\Config;
use Rolepod\Wp\Security\SessionToken;
use Rolepod\Wp\Skills\Catalog;
use Rolepod\Wp\Skills\Cpt;
use Rolepod\Wp\Skills\Parser;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Site-owned agent skills — CRUD over the rolepod_wp_skill CPT.
 *
 *   GET    /wp-json/wplab/v1/skills                 → catalog (slug+desc, no body)
 *   GET    /wp-json/wplab/v1/skills/<slug>          → full record + rendered SKILL.md
 *   POST   /wp-json/wplab/v1/skills                 → create/update  (session_token)
 *   POST   /wp-json/wplab/v1/skills/<slug>/edit     → patch fields   (session_token)
 *   DELETE /wp-json/wplab/v1/skills/<slug>          → trash          (session_token)
 *
 * Reads need only manage_options (content is not sensitive power surface).
 * Mutations additionally require a valid session token, like every other
 * write endpoint. Mutations are audited via Log and recoverable via the CPT's
 * native revisions (edits) or the trash (deletes) — nothing is destroyed.
 */
final class Skills
{
    private const SLUG_RE = '(?P<slug>[a-z0-9][a-z0-9-]*)';

    public static function register(): void
    {
        $ns = ROLEPOD_WP_REST_NAMESPACE;

        register_rest_route($ns, '/skills', [
            [
                'methods' => 'GET',
                'callback' => [self::class, 'handleList'],
                'permission_callback' => [self::class, 'permissionRead'],
            ],
            [
                'methods' => 'POST',
                'callback' => [self::class, 'handleWrite'],
                'permission_callback' => [self::class, 'permissionWrite'],
                'args' => [
                    'session_token' => ['required' => true, 'type' => 'string'],
                    'title' => ['required' => true, 'type' => 'string'],
                    'description' => ['required' => false, 'type' => 'string'],
                    'content' => ['required' => true, 'type' => 'string'],
                    'enable_agentic' => ['required' => false, 'type' => 'boolean'],
                    'enable_prompt' => ['required' => false, 'type' => 'boolean'],
                    'on_conflict' => ['required' => false, 'type' => 'string', 'enum' => ['fail', 'replace', 'rename']],
                ],
            ],
        ]);

        register_rest_route($ns, '/skills/' . self::SLUG_RE, [
            [
                'methods' => 'GET',
                'callback' => [self::class, 'handleGet'],
                'permission_callback' => [self::class, 'permissionRead'],
            ],
            [
                'methods' => 'DELETE',
                'callback' => [self::class, 'handleDelete'],
                'permission_callback' => [self::class, 'permissionWrite'],
                'args' => [
                    'session_token' => ['required' => true, 'type' => 'string'],
                ],
            ],
        ]);

        register_rest_route($ns, '/skills/' . self::SLUG_RE . '/edit', [
            'methods' => 'POST',
            'callback' => [self::class, 'handleEdit'],
            'permission_callback' => [self::class, 'permissionWrite'],
            'args' => [
                'session_token' => ['required' => true, 'type' => 'string'],
                'title' => ['required' => false, 'type' => 'string'],
                'description' => ['required' => false, 'type' => 'string'],
                'content' => ['required' => false, 'type' => 'string'],
                'enable_agentic' => ['required' => false, 'type' => 'boolean'],
                'enable_prompt' => ['required' => false, 'type' => 'boolean'],
            ],
        ]);
    }

    public static function permissionRead(WP_REST_Request $req)
    {
        if (!Config::endpointsEnabled()) {
            return new WP_Error('rolepod_wp_disabled', 'Companion endpoints disabled.', ['status' => 403]);
        }
        if (!current_user_can('manage_options')) {
            return new WP_Error('rolepod_wp_unauthorized', 'manage_options required.', ['status' => 403]);
        }
        return true;
    }

    /** Write routes share the read gate; the session-token check happens in the handler. */
    public static function permissionWrite(WP_REST_Request $req)
    {
        return self::permissionRead($req);
    }

    // --- reads ---------------------------------------------------------------

    public static function handleList(WP_REST_Request $req): WP_REST_Response
    {
        return new WP_REST_Response([
            'ok' => true,
            'skills' => Catalog::catalog(),
        ], 200);
    }

    public static function handleGet(WP_REST_Request $req): WP_REST_Response
    {
        $slug = (string) $req->get_param('slug');
        $skill = Catalog::find($slug);
        if ($skill === null) {
            return new WP_REST_Response(['ok' => true, 'found' => false, 'slug' => $slug], 200);
        }

        return new WP_REST_Response([
            'ok' => true,
            'found' => true,
            'slug' => $skill['slug'],
            'name' => $skill['name'],
            'description' => $skill['description'],
            'content' => $skill['content'],
            'skill_md' => Parser::renderSkillMd($skill),
            'enable_agentic' => $skill['enable_agentic'],
            'enable_prompt' => $skill['enable_prompt'],
        ], 200);
    }

    // --- mutations -----------------------------------------------------------

    public static function handleWrite(WP_REST_Request $req): WP_REST_Response
    {
        $tokenError = self::verifyToken($req);
        if ($tokenError !== null) {
            return $tokenError;
        }

        $slug = Parser::normalizeSlug((string) $req->get_param('title'));
        if ($slug === '') {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'INVALID_TITLE',
                'error_message' => 'Title must contain at least one letter or digit; it becomes the lowercase dash-separated slug.',
            ], 400);
        }

        $description = trim((string) $req->get_param('description'));
        $content = Parser::unescapeIfDoubleEncoded((string) $req->get_param('content'));
        if (strlen($content) > Parser::MAX_BODY_BYTES) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'BODY_TOO_LARGE',
                'error_message' => sprintf('Body exceeds the %d-byte limit.', Parser::MAX_BODY_BYTES),
            ], 400);
        }

        $onConflict = (string) ($req->get_param('on_conflict') ?? 'fail');
        if (!in_array($onConflict, ['fail', 'replace', 'rename'], true)) {
            $onConflict = 'fail';
        }

        $existing = self::findBySlugAnyStatus($slug);
        $action = 'created';

        if ($existing instanceof WP_Post) {
            if ($onConflict === 'fail') {
                return new WP_REST_Response([
                    'ok' => false,
                    'error_code' => 'SLUG_EXISTS',
                    'error_message' => 'A skill with this title already exists. Pass on_conflict=replace to overwrite or rename to auto-suffix.',
                    'slug' => $slug,
                    'suggested_slug' => self::findFreeSlug($slug),
                ], 409);
            }
            if ($onConflict === 'rename') {
                $slug = self::findFreeSlug($slug);
                $existing = null;
                $action = 'renamed';
            } else {
                $action = 'updated';
            }
        }

        $postarr = [
            'post_type' => Cpt::POST_TYPE,
            'post_status' => 'publish',
            'post_title' => $slug,
            'post_name' => $slug,
            'post_excerpt' => $description,
            'post_content' => $content,
        ];
        if ($existing instanceof WP_Post) {
            $postarr['ID'] = $existing->ID;
            $postId = wp_update_post(wp_slash($postarr), true);
        } else {
            $postId = wp_insert_post(wp_slash($postarr), true);
        }

        if (is_wp_error($postId)) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'PERSIST_FAILED',
                'error_message' => $postId->get_error_message(),
            ], 500);
        }

        self::applyFlag($req, (int) $postId, 'enable_agentic', Cpt::META_ENABLE_AGENTIC, true);
        self::applyFlag($req, (int) $postId, 'enable_prompt', Cpt::META_ENABLE_PROMPT, false);

        // Re-parse the stored body so the agent gets the same soft warnings the
        // parser would raise (empty description, oversized body, …) — actionable
        // feedback without a second round-trip.
        $parsed = Parser::parse(Parser::renderSkillMd([
            'slug' => $slug,
            'description' => $description,
            'content' => $content,
        ]));

        $auditId = Log::append([
            'endpoint' => 'skills-write',
            'user' => (string) wp_get_current_user()->user_login,
            'site_url' => (string) get_option('siteurl'),
            'result' => 'success',
            'meta' => ['slug' => $slug, 'action' => $action],
        ]);

        return new WP_REST_Response([
            'ok' => true,
            'slug' => $slug,
            'action' => $action,
            'warnings' => $parsed['warnings'],
            'audit_id' => $auditId,
        ], 200);
    }

    public static function handleEdit(WP_REST_Request $req): WP_REST_Response
    {
        $tokenError = self::verifyToken($req);
        if ($tokenError !== null) {
            return $tokenError;
        }

        $slug = (string) $req->get_param('slug');
        $existing = self::findBySlugAnyStatus($slug);
        if (!$existing instanceof WP_Post) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'NOT_FOUND',
                'error_message' => sprintf('No skill with slug "%s".', $slug),
            ], 404);
        }

        $postarr = ['ID' => $existing->ID];

        if ($req->has_param('description')) {
            $postarr['post_excerpt'] = trim((string) $req->get_param('description'));
        }
        if ($req->has_param('content')) {
            $content = Parser::unescapeIfDoubleEncoded((string) $req->get_param('content'));
            if (strlen($content) > Parser::MAX_BODY_BYTES) {
                return new WP_REST_Response([
                    'ok' => false,
                    'error_code' => 'BODY_TOO_LARGE',
                    'error_message' => sprintf('Body exceeds the %d-byte limit.', Parser::MAX_BODY_BYTES),
                ], 400);
            }
            $postarr['post_content'] = $content;
        }

        if (count($postarr) > 1) {
            $updated = wp_update_post(wp_slash($postarr), true);
            if (is_wp_error($updated)) {
                return new WP_REST_Response([
                    'ok' => false,
                    'error_code' => 'PERSIST_FAILED',
                    'error_message' => $updated->get_error_message(),
                ], 500);
            }
        }

        self::applyFlag($req, $existing->ID, 'enable_agentic', Cpt::META_ENABLE_AGENTIC, true);
        self::applyFlag($req, $existing->ID, 'enable_prompt', Cpt::META_ENABLE_PROMPT, false);

        $auditId = Log::append([
            'endpoint' => 'skills-edit',
            'user' => (string) wp_get_current_user()->user_login,
            'site_url' => (string) get_option('siteurl'),
            'result' => 'success',
            'meta' => ['slug' => $existing->post_name],
        ]);

        $fresh = Catalog::find($existing->post_name);

        return new WP_REST_Response([
            'ok' => true,
            'slug' => $existing->post_name,
            'action' => 'updated',
            'skill' => $fresh,
            'audit_id' => $auditId,
        ], 200);
    }

    public static function handleDelete(WP_REST_Request $req): WP_REST_Response
    {
        $tokenError = self::verifyToken($req);
        if ($tokenError !== null) {
            return $tokenError;
        }

        $slug = (string) $req->get_param('slug');
        $existing = self::findBySlugAnyStatus($slug);
        if (!$existing instanceof WP_Post) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'NOT_FOUND',
                'error_message' => sprintf('No skill with slug "%s".', $slug),
            ], 404);
        }

        // Trash, not force-delete — recoverable from the WP trash. The agent
        // should never be able to permanently destroy a site playbook.
        $result = wp_trash_post($existing->ID);
        if ($result === false || $result === null) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'DELETE_FAILED',
                'error_message' => 'WordPress refused to trash the skill post.',
            ], 500);
        }

        $auditId = Log::append([
            'endpoint' => 'skills-delete',
            'user' => (string) wp_get_current_user()->user_login,
            'site_url' => (string) get_option('siteurl'),
            'result' => 'success',
            'meta' => ['slug' => $slug],
        ]);

        return new WP_REST_Response([
            'ok' => true,
            'slug' => $slug,
            'action' => 'trashed',
            'recoverable' => true,
            'audit_id' => $auditId,
        ], 200);
    }

    // --- helpers -------------------------------------------------------------

    private static function verifyToken(WP_REST_Request $req): ?WP_REST_Response
    {
        $token = (string) $req->get_param('session_token');
        if (!SessionToken::verify($token, get_current_user_id())) {
            return new WP_REST_Response(['ok' => false, 'error_code' => 'INVALID_OR_EXPIRED_TOKEN'], 401);
        }
        return null;
    }

    private static function applyFlag(WP_REST_Request $req, int $postId, string $param, string $metaKey, bool $default): void
    {
        if ($req->has_param($param)) {
            update_post_meta($postId, $metaKey, (bool) $req->get_param($param));
        } elseif (get_post_meta($postId, $metaKey, true) === '') {
            update_post_meta($postId, $metaKey, $default);
        }
    }

    private static function findBySlugAnyStatus(string $slug): ?WP_Post
    {
        $slug = Parser::normalizeSlug($slug);
        if ($slug === '') {
            return null;
        }
        $posts = get_posts([
            'post_type' => Cpt::POST_TYPE,
            'post_status' => ['publish', 'draft', 'pending'],
            'name' => $slug,
            'posts_per_page' => 1,
            'no_found_rows' => true,
        ]);
        $post = $posts[0] ?? null;
        return $post instanceof WP_Post ? $post : null;
    }

    private static function findFreeSlug(string $slug): string
    {
        for ($i = 2; $i <= 9999; $i++) {
            $candidate = $slug . '-' . $i;
            if (!self::findBySlugAnyStatus($candidate) instanceof WP_Post) {
                return $candidate;
            }
        }
        return $slug . '-' . substr((string) time(), -6);
    }
}
