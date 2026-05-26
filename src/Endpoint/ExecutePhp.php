<?php
declare(strict_types=1);

namespace Rolepod\Wp\Endpoint;

use Rolepod\Wp\Audit\Log;
use Rolepod\Wp\Config;
use Rolepod\Wp\Security\AstScreen;
use Rolepod\Wp\Security\ProductionGuard;
use Rolepod\Wp\Security\SessionToken;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * POST /wp-json/wplab/v1/execute-php
 *
 * Runs a PHP payload inside the live WP request lifecycle — IF AND ONLY IF
 * all guards pass:
 *   1. Endpoints enabled (master toggle)
 *   2. execute_php_enabled toggle ON (v0.1 defaults to OFF)
 *   3. User has manage_options
 *   4. Valid session token
 *   5. Production guard does not match siteurl
 *   6. AST screen approves the payload
 *
 * v0.1 ships with execute_php_enabled=false by default. Admin must explicitly
 * flip it ON via Settings → Rolepod for WordPress. v0.2 will default to ON once
 * the audit log + AST screen have been dogfooded.
 */
final class ExecutePhp
{
    public static function register(): void
    {
        register_rest_route(
            ROLEPOD_WP_REST_NAMESPACE,
            '/execute-php',
            [
                'methods' => 'POST',
                'callback' => [self::class, 'handle'],
                'permission_callback' => [self::class, 'permission'],
                'args' => [
                    'session_token' => ['required' => true, 'type' => 'string'],
                    'payload' => ['required' => true, 'type' => 'string'],
                    'timeout_ms' => ['required' => false, 'type' => 'integer', 'default' => 5000],
                ],
            ]
        );
    }

    public static function permission(WP_REST_Request $req)
    {
        if (!Config::endpointsEnabled()) {
            return new WP_Error('rolepod_wp_disabled', 'Companion endpoints disabled.', ['status' => 403]);
        }
        if (!Config::executePhpEnabled()) {
            return new WP_Error(
                'rolepod_wp_execute_php_disabled',
                'execute-php endpoint is OFF (v0.1 default). Enable in Settings → Rolepod for WordPress.',
                ['status' => 503]
            );
        }
        if (!current_user_can('manage_options')) {
            return new WP_Error('rolepod_wp_unauthorized', 'manage_options required.', ['status' => 403]);
        }
        return true;
    }

    public static function handle(WP_REST_Request $req): WP_REST_Response
    {
        $payload = (string) $req->get_param('payload');
        $token = (string) $req->get_param('session_token');
        $timeoutMs = max(100, min(30_000, (int) $req->get_param('timeout_ms')));
        $userId = get_current_user_id();
        $siteurl = (string) get_option('siteurl');

        // Production block — unconditional
        $matched = ProductionGuard::matchedPattern();
        if ($matched !== null) {
            $auditId = Log::append([
                'endpoint' => 'execute-php',
                'user' => (string) wp_get_current_user()->user_login,
                'site_url' => $siteurl,
                'result' => 'rejected',
                'error' => "PRODUCTION_BLOCKED (matched={$matched})",
                'payload_sha256' => hash('sha256', $payload),
            ]);
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'PRODUCTION_BLOCKED',
                'error_message' => "siteurl matches production pattern: {$matched}",
                'audit_id' => $auditId,
            ], 403);
        }

        // Session token check
        if (!SessionToken::verify($token, $userId)) {
            $auditId = Log::append([
                'endpoint' => 'execute-php',
                'user' => (string) wp_get_current_user()->user_login,
                'site_url' => $siteurl,
                'result' => 'rejected',
                'error' => 'INVALID_OR_EXPIRED_TOKEN',
                'payload_sha256' => hash('sha256', $payload),
            ]);
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'INVALID_OR_EXPIRED_TOKEN',
                'error_message' => 'session_token missing, invalid, or expired — re-handshake',
                'audit_id' => $auditId,
            ], 401);
        }

        // AST screen
        $screen = AstScreen::screen($payload);
        if (!$screen['ok']) {
            $auditId = Log::append([
                'endpoint' => 'execute-php',
                'user' => (string) wp_get_current_user()->user_login,
                'site_url' => $siteurl,
                'result' => 'rejected',
                'error' => 'AST_REJECTED: ' . ($screen['error'] ?? 'unknown'),
                'payload' => $payload,
            ]);
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'AST_REJECTED',
                'error_message' => $screen['error'] ?? 'forbidden token',
                'token' => $screen['token'] ?? null,
                'audit_id' => $auditId,
            ], 400);
        }

        // Eval — guarded
        @set_time_limit(intval(ceil($timeoutMs / 1000)));
        $stdout = '';
        $returnValue = null;
        $errorMsg = null;
        ob_start();
        try {
            $returnValue = eval($payload); // phpcs:ignore Squiz.PHP.Eval -- guarded by AST screen + prod block + session token
        } catch (\Throwable $t) {
            $errorMsg = get_class($t) . ': ' . $t->getMessage();
        }
        $stdout = (string) ob_get_clean();

        $auditId = Log::append([
            'endpoint' => 'execute-php',
            'user' => (string) wp_get_current_user()->user_login,
            'site_url' => $siteurl,
            'result' => $errorMsg === null ? 'success' : 'error',
            'error' => $errorMsg,
            'payload' => $payload,
        ]);

        $body = [
            'ok' => $errorMsg === null,
            'return_value' => $returnValue,
            'stdout' => $stdout,
            'duration_ms' => 0, // v0.1 stub; v0.2 will measure
            'php_warnings' => [],
            'audit_id' => $auditId,
        ];
        if ($errorMsg !== null) {
            $body['error_message'] = $errorMsg;
        }

        return new WP_REST_Response($body, $errorMsg === null ? 200 : 500);
    }
}
