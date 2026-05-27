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
 * POST /wp-json/wplab/v1/option-set { session_token, name, value, autoload? }
 * POST /wp-json/wplab/v1/option-get { session_token, name, default? }
 *
 * Direct wp_options access via update_option() / get_option(). Bypasses WP REST
 * /wp/v2/settings allowlist limitation (REST settings only exposes ~10 fields
 * under different names than raw wp_options — blogname/blogdescription/
 * timezone_string etc. silently get ignored if passed to REST settings).
 *
 * Safety: refuses to write keys that are WP-managed and dangerous to overwrite
 * arbitrarily (db_version, secret, recovery_*, auth_*). Allowlist sites for
 * extreme paranoia can set a custom blocklist via the
 * `rolepod_wp_option_set_blocklist` filter.
 */
final class Options
{
    /**
     * Hard refusal — these keys are WP-managed and writing them via this
     * endpoint can break the install. Caller must use wp-cli or execute-php
     * with explicit intent if they really need to touch these.
     */
    private const BLOCKED_KEYS = [
        'db_version',
        'initial_db_version',
        'secret',
        'recovery_keys',
        'auth_key',
        'auth_salt',
        'logged_in_key',
        'logged_in_salt',
        'nonce_key',
        'nonce_salt',
        'secure_auth_key',
        'secure_auth_salt',
        'rewrite_rules', // computed; setting raw breaks routing
    ];

    public static function register(): void
    {
        register_rest_route(
            ROLEPOD_WP_REST_NAMESPACE,
            '/option-set',
            [
                'methods' => 'POST',
                'callback' => [self::class, 'handleSet'],
                'permission_callback' => [self::class, 'permission'],
                'args' => [
                    'session_token' => ['required' => true, 'type' => 'string'],
                    'name' => ['required' => true, 'type' => 'string'],
                    'value' => ['required' => true],
                    'autoload' => ['required' => false, 'type' => 'string'],
                ],
            ]
        );

        register_rest_route(
            ROLEPOD_WP_REST_NAMESPACE,
            '/option-get',
            [
                'methods' => 'POST',
                'callback' => [self::class, 'handleGet'],
                'permission_callback' => [self::class, 'permission'],
                'args' => [
                    'session_token' => ['required' => true, 'type' => 'string'],
                    'name' => ['required' => true, 'type' => 'string'],
                    'default' => ['required' => false],
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

    public static function handleSet(WP_REST_Request $req): WP_REST_Response
    {
        $userId = get_current_user_id();
        $token = (string) $req->get_param('session_token');
        if (!SessionToken::verify($token, $userId)) {
            return new WP_REST_Response(['ok' => false, 'error_code' => 'INVALID_OR_EXPIRED_TOKEN'], 401);
        }

        $name = trim((string) $req->get_param('name'));
        if ($name === '') {
            return new WP_REST_Response(['ok' => false, 'error_code' => 'NAME_REQUIRED'], 400);
        }

        $blocklist = (array) apply_filters('rolepod_wp_option_set_blocklist', self::BLOCKED_KEYS);
        if (in_array($name, $blocklist, true)) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'OPTION_BLOCKED',
                'error_message' => 'WP-managed key — refuse to overwrite via this endpoint.',
                'key' => $name,
            ], 403);
        }

        $value = $req->get_param('value');
        $autoloadParam = $req->get_param('autoload');
        $autoload = null;
        if ($autoloadParam !== null) {
            $autoloadParam = (string) $autoloadParam;
            if (in_array($autoloadParam, ['yes', 'no'], true)) {
                $autoload = $autoloadParam;
            }
        }

        $previous = get_option($name, null);
        $changed = $autoload !== null
            ? update_option($name, $value, $autoload)
            : update_option($name, $value);

        $auditId = Log::append([
            'endpoint' => 'option-set',
            'user' => (string) wp_get_current_user()->user_login,
            'site_url' => (string) get_option('siteurl'),
            'result' => 'success',
            'error' => null,
            'meta' => ['option' => $name, 'changed' => $changed],
        ]);

        return new WP_REST_Response([
            'ok' => true,
            'name' => $name,
            'changed' => (bool) $changed,
            'previous' => $previous,
            'current' => get_option($name, null),
            'audit_id' => $auditId,
        ], 200);
    }

    public static function handleGet(WP_REST_Request $req): WP_REST_Response
    {
        $userId = get_current_user_id();
        $token = (string) $req->get_param('session_token');
        if (!SessionToken::verify($token, $userId)) {
            return new WP_REST_Response(['ok' => false, 'error_code' => 'INVALID_OR_EXPIRED_TOKEN'], 401);
        }

        $name = trim((string) $req->get_param('name'));
        if ($name === '') {
            return new WP_REST_Response(['ok' => false, 'error_code' => 'NAME_REQUIRED'], 400);
        }

        $default = $req->get_param('default');
        $value = get_option($name, $default);

        return new WP_REST_Response([
            'ok' => true,
            'name' => $name,
            'value' => $value,
            'exists' => $value !== false || get_option($name, '__missing__') !== '__missing__',
        ], 200);
    }
}
