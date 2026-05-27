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
 * POST /wp-json/wplab/v1/db-query
 *   Body: { session_token, sql, params? }
 *
 * Run a SELECT-only SQL query against the WP database via $wpdb. Returns
 * rows as JSON. Use this instead of `wp db query` over the wp-cli endpoint
 * when the SQL contains shell-quoting hazards (single/double quotes, $, !)
 * OR when the query uses the `{prefix}` placeholder (which wp-cli does NOT
 * substitute — confirmed bug in MCP-side diagnose tool that wrote
 * `FROM {prefix}postmeta` literal and got "Unknown table" mysql error).
 *
 * Safety: refuses any statement that isn't a pure SELECT (anchor checked
 * on the trimmed, comment-stripped query). Production guard not enforced
 * (read-only). Parameters bound via $wpdb->prepare to prevent injection
 * even with admin-user privileges.
 *
 * Placeholder substitution: `{prefix}` in the SQL is replaced with
 * $wpdb->prefix server-side, so callers can write portable queries.
 */
final class DbQuery
{
    public static function register(): void
    {
        register_rest_route(
            ROLEPOD_WP_REST_NAMESPACE,
            '/db-query',
            [
                'methods' => 'POST',
                'callback' => [self::class, 'handle'],
                'permission_callback' => [self::class, 'permission'],
                'args' => [
                    'session_token' => ['required' => true, 'type' => 'string'],
                    'sql' => ['required' => true, 'type' => 'string'],
                    'params' => ['required' => false, 'type' => 'array'],
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
        global $wpdb;
        $userId = get_current_user_id();
        $token = (string) $req->get_param('session_token');
        if (!SessionToken::verify($token, $userId)) {
            return new WP_REST_Response(['ok' => false, 'error_code' => 'INVALID_OR_EXPIRED_TOKEN'], 401);
        }

        $sql = trim((string) $req->get_param('sql'));
        if ($sql === '') {
            return new WP_REST_Response(['ok' => false, 'error_code' => 'SQL_REQUIRED'], 400);
        }

        // SELECT-only guard. Strip leading SQL comments + WITH CTE prefix
        // for the keyword check.
        $stripped = preg_replace('!/\\*.*?\\*/!s', '', $sql) ?? '';
        $stripped = preg_replace('/^--.*$/m', '', $stripped) ?? '';
        $stripped = ltrim($stripped);
        if (!preg_match('/^(SELECT|WITH|SHOW|DESCRIBE|EXPLAIN)\\b/i', $stripped)) {
            $auditId = Log::append([
                'endpoint' => 'db-query',
                'user' => (string) wp_get_current_user()->user_login,
                'site_url' => (string) get_option('siteurl'),
                'result' => 'rejected',
                'error' => 'NON_SELECT_REFUSED',
                'meta' => ['sql_preview' => substr($sql, 0, 200)],
            ]);
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'NON_SELECT_REFUSED',
                'error_message' => 'Only SELECT / SHOW / DESCRIBE / EXPLAIN / WITH allowed. Use execute-php for writes.',
                'audit_id' => $auditId,
            ], 400);
        }

        // {prefix} → $wpdb->prefix (default wp_). Lets callers write portable
        // queries without hard-coding the install's table prefix.
        $sqlResolved = str_replace('{prefix}', $wpdb->prefix, $sql);

        // Parameter binding via $wpdb->prepare. params is an array of values
        // matching %s/%d/%f placeholders in $sql.
        $params = (array) $req->get_param('params');
        if (!empty($params)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $prepared = $wpdb->prepare($sqlResolved, $params);
            if ($prepared === null || $prepared === false) {
                return new WP_REST_Response([
                    'ok' => false,
                    'error_code' => 'PREPARE_FAILED',
                    'error_message' => 'wpdb->prepare returned null/false — placeholder/value count mismatch?',
                ], 400);
            }
            $sqlResolved = $prepared;
        }

        // Capture errors via $wpdb->last_error rather than throwing.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results($sqlResolved, ARRAY_A);
        if ($rows === null && $wpdb->last_error !== '') {
            $auditId = Log::append([
                'endpoint' => 'db-query',
                'user' => (string) wp_get_current_user()->user_login,
                'site_url' => (string) get_option('siteurl'),
                'result' => 'error',
                'error' => $wpdb->last_error,
                'meta' => ['sql_preview' => substr($sql, 0, 200)],
            ]);
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'QUERY_FAILED',
                'error_message' => $wpdb->last_error,
                'audit_id' => $auditId,
            ], 500);
        }

        $auditId = Log::append([
            'endpoint' => 'db-query',
            'user' => (string) wp_get_current_user()->user_login,
            'site_url' => (string) get_option('siteurl'),
            'result' => 'success',
            'error' => null,
            'meta' => ['sql_preview' => substr($sql, 0, 200), 'rows' => is_array($rows) ? count($rows) : 0],
        ]);

        return new WP_REST_Response([
            'ok' => true,
            'rows' => is_array($rows) ? $rows : [],
            'count' => is_array($rows) ? count($rows) : 0,
            'audit_id' => $auditId,
        ], 200);
    }
}
