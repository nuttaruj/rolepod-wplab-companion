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
 * One-time admin login link — closes the "AI needs to surface a wp-admin URL"
 * gap without exposing the admin password.
 *
 *   POST /wp-json/wplab/v1/admin/one-time-login   (session_token + manage_options)
 *     Body: { session_token, [destination] }
 *     Returns: { url, expires_in_seconds, token }
 *
 * Flow:
 *   1. MCP requests a one-time link → companion mints a 32-hex token, stores
 *      a transient { user_id, expires_at, redirect } keyed by the token,
 *      returns the wp-admin URL with ?rolepod_wp_otl=<token>.
 *   2. User opens the URL in any browser (no admin password needed).
 *   3. WP `init` hook intercepts the param, validates the transient,
 *      single-use deletes it, calls wp_set_auth_cookie() for the issuing
 *      admin, and redirects to the destination (default: dashboard).
 *
 * TTL: 5 minutes. Single-use enforced atomically (delete-then-act). Token
 * is 256-bit entropy hex; sniffing the URL recovers a one-shot login that
 * burns on first use.
 */
final class OneTimeLogin
{
    private const TTL_SECONDS = 300;
    private const TOKEN_PREFIX = 'rolepod_wp_otl_';
    private const QUERY_PARAM = 'rolepod_wp_otl';

    public static function register(): void
    {
        register_rest_route(
            ROLEPOD_WP_REST_NAMESPACE,
            '/admin/one-time-login',
            [
                'methods' => 'POST',
                'callback' => [self::class, 'handleMint'],
                'permission_callback' => [self::class, 'permission'],
                'args' => [
                    'session_token' => ['required' => true, 'type' => 'string'],
                    'destination' => ['required' => false, 'type' => 'string'],
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

    public static function handleMint(WP_REST_Request $req): WP_REST_Response
    {
        $userId = get_current_user_id();
        $token = (string) $req->get_param('session_token');
        if (!SessionToken::verify($token, $userId)) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'INVALID_OR_EXPIRED_TOKEN',
            ], 401);
        }

        $destination = (string) ($req->get_param('destination') ?? '');
        if ($destination === '') {
            $destination = admin_url('/');
        }

        $otlToken = self::TOKEN_PREFIX . bin2hex(random_bytes(16));
        set_transient($otlToken, [
            'user_id' => $userId,
            'destination' => $destination,
            'expires_at' => time() + self::TTL_SECONDS,
            'issued_at' => time(),
        ], self::TTL_SECONDS);

        $siteurl = get_site_url();
        $url = $siteurl . '/?' . self::QUERY_PARAM . '=' . rawurlencode($otlToken);

        Log::append([
            'endpoint' => 'admin/one-time-login/mint',
            'user' => (string) wp_get_current_user()->user_login,
            'site_url' => (string) get_option('siteurl'),
            'result' => 'success',
            'error' => null,
        ]);

        return new WP_REST_Response([
            'ok' => true,
            'url' => $url,
            'token' => $otlToken,
            'expires_in_seconds' => self::TTL_SECONDS,
            'destination' => $destination,
        ], 200);
    }

    /**
     * Intercept the ?rolepod_wp_otl=<token> query param on any request. Runs
     * on `init` priority 1 so it fires before WP's main routing.
     */
    public static function maybeIntercept(): void
    {
        if (!isset($_GET[self::QUERY_PARAM])) {
            return;
        }
        $token = (string) wp_unslash($_GET[self::QUERY_PARAM]);
        if (strpos($token, self::TOKEN_PREFIX) !== 0) {
            return;
        }

        $payload = get_transient($token);
        if (!is_array($payload)) {
            // Already used / expired / forged.
            wp_die(
                esc_html__('Rolepod one-time login link is invalid or already used. Generate a new one from the AI.', 'rolepod-wp'),
                esc_html__('Login link invalid', 'rolepod-wp'),
                ['response' => 403]
            );
        }

        // Single-use: delete before act.
        delete_transient($token);

        if (($payload['expires_at'] ?? 0) < time()) {
            wp_die(
                esc_html__('Rolepod one-time login link expired. Generate a new one from the AI.', 'rolepod-wp'),
                esc_html__('Login link expired', 'rolepod-wp'),
                ['response' => 403]
            );
        }

        $userId = (int) ($payload['user_id'] ?? 0);
        $user = $userId > 0 ? get_userdata($userId) : false;
        if (!$user) {
            wp_die(
                esc_html__('Rolepod one-time login link references a user that no longer exists.', 'rolepod-wp'),
                esc_html__('Login link invalid', 'rolepod-wp'),
                ['response' => 403]
            );
        }

        wp_set_current_user($user->ID, $user->user_login);
        wp_set_auth_cookie($user->ID, false, is_ssl());

        Log::append([
            'endpoint' => 'admin/one-time-login/redeem',
            'user' => (string) $user->user_login,
            'site_url' => (string) get_option('siteurl'),
            'result' => 'success',
            'error' => null,
        ]);

        $destination = (string) ($payload['destination'] ?? admin_url('/'));
        wp_safe_redirect($destination);
        exit;
    }
}
