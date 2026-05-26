<?php
declare(strict_types=1);

namespace Rolepod\Wp\Endpoint;

use Rolepod\Wp\Config;
use Rolepod\Wp\Security\SessionToken;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * POST /wp-json/wplab/v1/request-observer (v0.2)
 *
 * Register a hook + queue observed state for mid-request introspection.
 * Stores observation buckets in a transient keyed by session_token; the Lead
 * polls a follow-up GET to drain.
 *
 * v0.2 ships a basic implementation — register a hook that records
 * `$wp_filter` at fire time. Future versions add structured per-hook
 * observation predicates.
 */
final class RequestObserver
{
    private const TRANSIENT_PREFIX = 'wplab_observer_';

    public static function register(): void
    {
        register_rest_route(
            ROLEPOD_WP_REST_NAMESPACE,
            '/request-observer',
            [
                'methods' => 'POST',
                'callback' => [self::class, 'handlePost'],
                'permission_callback' => [self::class, 'permission'],
                'args' => [
                    'session_token' => ['required' => true, 'type' => 'string'],
                    'hook' => ['required' => true, 'type' => 'string'],
                    'priority' => ['required' => false, 'type' => 'integer', 'default' => 10],
                ],
            ]
        );
        register_rest_route(
            ROLEPOD_WP_REST_NAMESPACE,
            '/request-observer/poll',
            [
                'methods' => 'GET',
                'callback' => [self::class, 'handlePoll'],
                'permission_callback' => [self::class, 'permission'],
                'args' => [
                    'session_token' => ['required' => true, 'type' => 'string'],
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

    public static function handlePost(WP_REST_Request $req): WP_REST_Response
    {
        $userId = get_current_user_id();
        $token = (string) $req->get_param('session_token');
        if (!SessionToken::verify($token, $userId)) {
            return new WP_REST_Response(['ok' => false, 'error_code' => 'INVALID_OR_EXPIRED_TOKEN'], 401);
        }
        $hook = (string) $req->get_param('hook');
        $priority = (int) $req->get_param('priority');

        // Register observer. Use a transient list keyed by token.
        $key = self::TRANSIENT_PREFIX . md5($token);
        $bucket = get_transient($key);
        if (!is_array($bucket)) {
            $bucket = ['observations' => [], 'hooks' => []];
        }
        $bucket['hooks'][] = ['hook' => $hook, 'priority' => $priority];
        set_transient($key, $bucket, 600); // 10 min

        // Inject closure that records each fire.
        add_action($hook, static function (...$args) use ($key, $hook) {
            $current = get_transient($key);
            if (!is_array($current)) $current = ['observations' => [], 'hooks' => []];
            $current['observations'][] = [
                'hook' => $hook,
                'timestamp' => microtime(true),
                'arg_count' => count($args),
            ];
            // Keep only most-recent 100 observations
            if (count($current['observations']) > 100) {
                $current['observations'] = array_slice($current['observations'], -100);
            }
            set_transient($key, $current, 600);
        }, $priority, 99);

        return new WP_REST_Response(['ok' => true, 'observing' => $hook, 'priority' => $priority], 200);
    }

    public static function handlePoll(WP_REST_Request $req): WP_REST_Response
    {
        $userId = get_current_user_id();
        $token = (string) $req->get_param('session_token');
        if (!SessionToken::verify($token, $userId)) {
            return new WP_REST_Response(['ok' => false, 'error_code' => 'INVALID_OR_EXPIRED_TOKEN'], 401);
        }
        $key = self::TRANSIENT_PREFIX . md5($token);
        $bucket = get_transient($key);
        $obs = is_array($bucket) ? ($bucket['observations'] ?? []) : [];
        $hooks = is_array($bucket) ? ($bucket['hooks'] ?? []) : [];
        return new WP_REST_Response([
            'ok' => true,
            'registered_hooks' => $hooks,
            'observations' => $obs,
        ], 200);
    }
}
