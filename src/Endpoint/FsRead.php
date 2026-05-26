<?php
declare(strict_types=1);

namespace Rolepod\Wp\Endpoint;

use Rolepod\Wp\Config;
use Rolepod\Wp\Security\SessionToken;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * POST /wp-json/wplab/v1/fs-read (v0.2)
 *
 * Read a file from the WP install (any path under ABSPATH). Lets RestTarget
 * do file reads without SSH.
 */
final class FsRead
{
    private const MAX_BYTES = 5 * 1024 * 1024; // 5 MiB

    public static function register(): void
    {
        register_rest_route(
            ROLEPOD_WP_REST_NAMESPACE,
            '/fs-read',
            [
                'methods' => 'POST',
                'callback' => [self::class, 'handle'],
                'permission_callback' => [self::class, 'permission'],
                'args' => [
                    'session_token' => ['required' => true, 'type' => 'string'],
                    'path' => ['required' => true, 'type' => 'string'],
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
            return new WP_REST_Response(['ok' => false, 'error_code' => 'INVALID_OR_EXPIRED_TOKEN'], 401);
        }

        $relPath = (string) $req->get_param('path');
        $absPath = realpath(ABSPATH . ltrim($relPath, '/'));
        $absRoot = realpath(ABSPATH);
        if ($absPath === false || $absRoot === false || strpos($absPath, $absRoot) !== 0) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'FS_SCOPE_VIOLATION',
                'error_message' => 'path escapes WP install root',
            ], 400);
        }
        if (!is_file($absPath)) {
            return new WP_REST_Response(['ok' => false, 'error_code' => 'FILE_NOT_FOUND'], 404);
        }
        $size = filesize($absPath);
        if ($size > self::MAX_BYTES) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'FILE_TOO_LARGE',
                'error_message' => "file is {$size} bytes; max " . self::MAX_BYTES,
            ], 413);
        }
        $content = file_get_contents($absPath);
        if ($content === false) {
            return new WP_REST_Response(['ok' => false, 'error_code' => 'READ_FAILED'], 500);
        }

        return new WP_REST_Response([
            'ok' => true,
            'path' => $relPath,
            'bytes' => $size,
            'content' => $content,
        ], 200);
    }
}
