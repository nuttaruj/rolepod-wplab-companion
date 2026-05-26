<?php
declare(strict_types=1);

namespace Rolepod\Wp\Endpoint;

use Rolepod\Wp\Audit\Log;
use Rolepod\Wp\Config;
use Rolepod\Wp\Security\ProductionGuard;
use Rolepod\Wp\Security\SessionToken;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * POST /wp-json/wplab/v1/wp-cli (v0.2, W-027)
 *
 * Proxy to wp-cli.phar executed via PHP exec(). Lets shared-hosting users
 * run wp-cli without installing it on the host (companion bundles or
 * fetches the phar to wp-content/uploads/wplab-bin/wp-cli.phar).
 *
 * If `exec()` is disabled in PHP config OR phar path is missing, returns
 * 503 with hint to enable exec OR install host-side wp-cli.
 */
final class WpCli
{
    public static function register(): void
    {
        register_rest_route(
            ROLEPOD_WP_REST_NAMESPACE,
            '/wp-cli',
            [
                'methods' => 'POST',
                'callback' => [self::class, 'handle'],
                'permission_callback' => [self::class, 'permission'],
                'args' => [
                    'session_token' => ['required' => true, 'type' => 'string'],
                    'args' => ['required' => true, 'type' => 'array'],
                    'timeout_seconds' => ['required' => false, 'type' => 'integer', 'default' => 30],
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

        if (!function_exists('exec') || in_array('exec', explode(',', (string) ini_get('disable_functions')), true)) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'EXEC_DISABLED',
                'error_message' => 'PHP exec() is disabled. Install wp-cli host-side OR use a host that allows exec().',
            ], 503);
        }

        $phar = self::resolvePharPath();
        if ($phar === null) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'WP_CLI_NOT_BUNDLED',
                'error_message' => 'wp-cli.phar not found at ' . self::expectedPharPath()
                    . '. Run: curl -L -o ' . self::expectedPharPath() . ' https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar',
            ], 503);
        }

        $args = (array) $req->get_param('args');
        $timeout = max(1, min(120, (int) $req->get_param('timeout_seconds')));
        $wpPath = ABSPATH;

        // Production guard for destructive ops — basic version. Real allow-list
        // lives on the Node MCP side (W-005); companion just refuses on prod.
        $matched = ProductionGuard::matchedPattern();
        if ($matched !== null && self::looksDestructive($args)) {
            $auditId = Log::append([
                'endpoint' => 'wp-cli',
                'user' => (string) wp_get_current_user()->user_login,
                'site_url' => (string) get_option('siteurl'),
                'result' => 'rejected',
                'error' => "PRODUCTION_BLOCKED (matched={$matched}); args=" . implode(' ', $args),
            ]);
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'PRODUCTION_BLOCKED',
                'audit_id' => $auditId,
            ], 403);
        }

        $escapedArgs = array_map('escapeshellarg', $args);
        $cmd = 'php ' . escapeshellarg($phar) . ' --path=' . escapeshellarg($wpPath)
            . ' --no-color ' . implode(' ', $escapedArgs) . ' 2>&1';

        $output = [];
        $exitCode = 0;
        @exec($cmd, $output, $exitCode);
        $stdout = implode("\n", $output);

        $auditId = Log::append([
            'endpoint' => 'wp-cli',
            'user' => (string) wp_get_current_user()->user_login,
            'site_url' => (string) get_option('siteurl'),
            'result' => $exitCode === 0 ? 'success' : 'error',
            'error' => $exitCode === 0 ? null : "exit_code={$exitCode}",
        ]);

        return new WP_REST_Response([
            'ok' => $exitCode === 0,
            'exit_code' => $exitCode,
            'stdout' => $stdout,
            'audit_id' => $auditId,
        ], 200);
    }

    private static function expectedPharPath(): string
    {
        $uploads = wp_upload_dir();
        $base = is_array($uploads) && !empty($uploads['basedir']) ? $uploads['basedir'] : ABSPATH . 'wp-content/uploads';
        return trailingslashit($base) . 'wplab-bin/wp-cli.phar';
    }

    private static function resolvePharPath(): ?string
    {
        $p = self::expectedPharPath();
        return is_file($p) ? $p : null;
    }

    /** Heuristic destructive-cmd detection. Real allow-list lives on Node side. */
    private static function looksDestructive(array $args): bool
    {
        if (count($args) === 0) return false;
        $head = strtolower(implode(' ', array_slice($args, 0, 2)));
        $destructive = ['db reset', 'db drop', 'core multisite-convert', 'plugin delete', 'theme delete'];
        foreach ($destructive as $d) {
            if (strpos($head, $d) === 0) return true;
        }
        return false;
    }
}
