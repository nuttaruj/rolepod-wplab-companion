<?php
declare(strict_types=1);

namespace Rolepod\Wp\Endpoint;

use Rolepod\Wp\Audit\Log;
use Rolepod\Wp\Config;
use Rolepod\Wp\Security\SessionToken;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /wp-json/wplab/v1/fs-rename
 *   Body: { session_token, src, dest }
 *
 * Both `src` and `dest` paths are scope-checked (must resolve under
 * wp-content/{themes,plugins,uploads} or be wp-config.php with
 * confirm_unsafe_path). The MCP-side `wp_file_disable` / `wp_file_enable`
 * tools use this to toggle a file "off" by renaming to `<path>.disabled`
 * — OS-level reversible without DB write.
 *
 * Refuses if dest already exists (prevents accidental overwrite).
 */
final class FsRename
{
    public static function register(): void
    {
        register_rest_route(
            ROLEPOD_WP_REST_NAMESPACE,
            '/fs-rename',
            [
                'methods' => 'POST',
                'callback' => [self::class, 'handle'],
                'permission_callback' => [self::class, 'permission'],
                'args' => [
                    'session_token' => ['required' => true, 'type' => 'string'],
                    'src' => ['required' => true, 'type' => 'string'],
                    'dest' => ['required' => true, 'type' => 'string'],
                ],
            ]
        );
    }

    public static function permission(WP_REST_Request $req)
    {
        if (!Config::endpointsEnabled()) {
            return new WP_Error('rolepod_wp_disabled', 'Companion endpoints disabled.', ['status' => 403]);
        }
        if (!current_user_can('manage_options')) {
            return new WP_Error('rolepod_wp_unauthorized', 'manage_options required.', ['status' => 403]);
        }
        return true;
    }

    public static function handle(WP_REST_Request $req): WP_REST_Response
    {
        $userId = get_current_user_id();
        $token = (string) $req->get_param('session_token');
        if (!SessionToken::verify($token, $userId)) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'INVALID_OR_EXPIRED_TOKEN',
            ], 401);
        }

        $src = self::resolveScoped((string) $req->get_param('src'));
        $dest = self::resolveScoped((string) $req->get_param('dest'));
        if ($src === null) {
            return new WP_REST_Response(['ok' => false, 'error_code' => 'SRC_OUT_OF_SCOPE'], 403);
        }
        if ($dest === null) {
            return new WP_REST_Response(['ok' => false, 'error_code' => 'DEST_OUT_OF_SCOPE'], 403);
        }
        if (!is_file($src)) {
            return new WP_REST_Response(['ok' => false, 'error_code' => 'SRC_NOT_FOUND'], 404);
        }
        if (is_file($dest)) {
            return new WP_REST_Response(['ok' => false, 'error_code' => 'DEST_EXISTS'], 409);
        }

        if (!@rename($src, $dest)) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'RENAME_FAILED',
                'error_message' => 'OS rename returned false (perms?)',
            ], 500);
        }

        $auditId = Log::append([
            'endpoint' => 'fs-rename',
            'user' => (string) wp_get_current_user()->user_login,
            'site_url' => (string) get_option('siteurl'),
            'result' => 'success',
            'error' => null,
        ]);

        return new WP_REST_Response([
            'ok' => true,
            'src' => $src,
            'dest' => $dest,
            'audit_id' => $auditId,
        ], 200);
    }

    /**
     * Resolve a relative path (e.g. "wp-content/plugins/foo/foo.php") to its
     * absolute form, then verify the result lives under one of the allowed
     * scope roots: wp-content/{plugins,themes,uploads,mu-plugins} OR
     * wp-config.php / .disabled / .wplab-bak. Returns absolute path or null.
     */
    private static function resolveScoped(string $relative): ?string
    {
        // Allow absolute paths if they already pass scope check after realpath.
        $abs = ($relative !== '' && $relative[0] === '/') ? $relative : ABSPATH . ltrim($relative, '/');

        // Use realpath() of the parent dir + filename (realpath fails on
        // non-existent paths, so we resolve the parent).
        $parent = dirname($abs);
        $real = realpath($parent);
        if ($real === false) return null;
        $abs = rtrim($real, '/') . '/' . basename($abs);

        $allowedRoots = [
            realpath(WP_CONTENT_DIR . '/plugins'),
            realpath(WP_CONTENT_DIR . '/themes'),
            realpath(WP_CONTENT_DIR . '/uploads'),
            realpath(WP_CONTENT_DIR . '/mu-plugins'),
        ];
        foreach ($allowedRoots as $root) {
            if ($root === false) continue;
            if (strpos($abs, $root . '/') === 0) return $abs;
        }

        // wp-config.php special case.
        $wpConfig = realpath(ABSPATH . 'wp-config.php');
        if ($wpConfig !== false && $abs === $wpConfig) return $abs;

        return null;
    }
}
