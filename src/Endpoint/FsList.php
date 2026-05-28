<?php
declare(strict_types=1);

namespace Rolepod\Wp\Endpoint;

use Rolepod\Wp\Config;
use Rolepod\Wp\Security\SessionToken;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET /wp-json/wplab/v1/fs-list?path=&depth=&include_hidden= (v2.11)
 *
 * Recursive directory listing. Reads are always allowed (no production
 * guard) — listing is non-mutating.
 *
 * Query:
 *   path           required — relative path under ABSPATH (default: '')
 *   depth          optional, 0..5 (default 2; depth=0 means just the dir itself)
 *   include_hidden optional bool (default false — skips dot-files)
 *   session_token  required (header `X-Wplab-Session` or query param)
 *
 * Response 200:
 *   { ok: true, root, entries: [
 *       { path, type: "file"|"dir", bytes, mtime, depth },
 *       ...
 *     ],
 *     truncated: bool  (true when MAX_ENTRIES hit)
 *   }
 */
final class FsList
{
    private const MAX_ENTRIES = 2000;
    private const MAX_DEPTH = 5;

    public static function register(): void
    {
        register_rest_route(
            ROLEPOD_WP_REST_NAMESPACE,
            '/fs-list',
            [
                'methods' => 'GET',
                'callback' => [self::class, 'handle'],
                'permission_callback' => [self::class, 'permission'],
                'args' => [
                    'session_token' => ['required' => true, 'type' => 'string'],
                    'path' => ['required' => true, 'type' => 'string'],
                    'depth' => ['required' => false, 'type' => 'integer', 'default' => 2],
                    'include_hidden' => ['required' => false, 'type' => 'boolean', 'default' => false],
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

        $relRoot = ltrim((string) $req->get_param('path'), '/');
        $depth = max(0, min(self::MAX_DEPTH, (int) $req->get_param('depth')));
        $includeHidden = (bool) $req->get_param('include_hidden');

        if (strpos($relRoot, '..') !== false) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'INVALID_PATH',
                'error_message' => 'no parent traversal',
            ], 400);
        }

        $absRoot = rtrim(ABSPATH . $relRoot, '/');
        if (!is_dir($absRoot)) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'NOT_A_DIRECTORY',
                'error_message' => "{$relRoot} is not a directory",
            ], 404);
        }
        // Refuse listings of WP root and parent dirs (would leak structure).
        $real = realpath($absRoot);
        $abs = realpath(ABSPATH);
        if ($real === false || $abs === false || strpos($real, $abs) !== 0) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'OUT_OF_SCOPE',
                'error_message' => "path resolves outside the WP install",
            ], 400);
        }

        $entries = [];
        $truncated = false;
        self::walk($absRoot, $relRoot, 0, $depth, $includeHidden, $entries, $truncated);
        return new WP_REST_Response([
            'ok' => true,
            'root' => $relRoot,
            'entries' => $entries,
            'truncated' => $truncated,
        ], 200);
    }

    /** @param array<int, array<string, mixed>> $entries */
    private static function walk(
        string $absDir,
        string $relDir,
        int $currentDepth,
        int $maxDepth,
        bool $includeHidden,
        array &$entries,
        bool &$truncated
    ): void {
        if ($truncated) {
            return;
        }
        $handle = @opendir($absDir);
        if ($handle === false) {
            return;
        }
        try {
            while (($name = readdir($handle)) !== false) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                if (!$includeHidden && $name[0] === '.') {
                    continue;
                }
                if (count($entries) >= self::MAX_ENTRIES) {
                    $truncated = true;
                    return;
                }
                $absChild = $absDir . '/' . $name;
                $relChild = $relDir === '' ? $name : ($relDir . '/' . $name);
                $isDir = is_dir($absChild);
                $stat = @stat($absChild);
                $entries[] = [
                    'path' => $relChild,
                    'type' => $isDir ? 'dir' : 'file',
                    'bytes' => $isDir ? 0 : (int) ($stat['size'] ?? 0),
                    'mtime' => (int) ($stat['mtime'] ?? 0),
                    'depth' => $currentDepth + 1,
                ];
                if ($isDir && $currentDepth < $maxDepth - 1) {
                    self::walk($absChild, $relChild, $currentDepth + 1, $maxDepth, $includeHidden, $entries, $truncated);
                }
            }
        } finally {
            closedir($handle);
        }
    }
}
