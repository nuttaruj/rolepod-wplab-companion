<?php
declare(strict_types=1);

namespace Rolepod\Wp\Endpoint;

use Rolepod\Wp\Audit\ChangeRecorder;
use Rolepod\Wp\Audit\Log;
use Rolepod\Wp\Audit\Toggler;
use Rolepod\Wp\Audit\HookWrapper;
use Rolepod\Wp\Config;
use Rolepod\Wp\Security\SessionToken;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST endpoints for the v2.3 Change Ledger.
 *
 *   POST /wplab/v1/changes/record       (MCP records a write)
 *   GET  /wplab/v1/changes              (list + filter)
 *   POST /wplab/v1/changes/toggle       (single toggle)
 *   POST /wplab/v1/changes/toggle-bulk  (bulk toggle)
 *   POST /wplab/v1/changes/panic        (disable all in window)
 *
 * All require session_token + manage_options + endpoints_enabled (except
 * /record which the MCP itself calls right after every write — same auth
 * surface so AI agents cannot bypass).
 */
final class Changes
{
    public static function register(): void
    {
        $ns = ROLEPOD_WP_REST_NAMESPACE;

        register_rest_route($ns, '/changes/record', [
            'methods' => 'POST',
            'callback' => [self::class, 'handleRecord'],
            'permission_callback' => [self::class, 'permission'],
            'args' => [
                'session_token' => ['required' => true, 'type' => 'string'],
                'category' => ['required' => true, 'type' => 'string'],
                'subcategory' => ['required' => true, 'type' => 'string'],
                'target_descriptor' => ['required' => true, 'type' => 'string'],
                'before_state' => ['required' => false],
                'after_state' => ['required' => false],
                'reversible' => ['required' => false, 'type' => 'boolean', 'default' => true],
                'source_tool' => ['required' => false, 'type' => 'string'],
                'source_session' => ['required' => false, 'type' => 'string'],
                'notes' => ['required' => false, 'type' => 'string'],
            ],
        ]);

        register_rest_route($ns, '/changes', [
            'methods' => 'GET',
            'callback' => [self::class, 'handleQuery'],
            'permission_callback' => [self::class, 'permission'],
            'args' => [
                'session_token' => ['required' => true, 'type' => 'string'],
                'category' => ['required' => false, 'type' => 'string'],
                'applied' => ['required' => false],
                'since_minutes' => ['required' => false, 'type' => 'integer'],
                'source_session' => ['required' => false, 'type' => 'string'],
                'limit' => ['required' => false, 'type' => 'integer', 'default' => 100],
            ],
        ]);

        register_rest_route($ns, '/changes/toggle', [
            'methods' => 'POST',
            'callback' => [self::class, 'handleToggle'],
            'permission_callback' => [self::class, 'permission'],
            'args' => [
                'session_token' => ['required' => true, 'type' => 'string'],
                'id' => ['required' => true, 'type' => 'integer'],
                'applied' => ['required' => true, 'type' => 'boolean'],
            ],
        ]);

        register_rest_route($ns, '/changes/toggle-bulk', [
            'methods' => 'POST',
            'callback' => [self::class, 'handleToggleBulk'],
            'permission_callback' => [self::class, 'permission'],
            'args' => [
                'session_token' => ['required' => true, 'type' => 'string'],
                'ids' => ['required' => true, 'type' => 'array'],
                'applied' => ['required' => true, 'type' => 'boolean'],
            ],
        ]);

        register_rest_route($ns, '/changes/panic', [
            'methods' => 'POST',
            'callback' => [self::class, 'handlePanic'],
            'permission_callback' => [self::class, 'permission'],
            'args' => [
                'session_token' => ['required' => true, 'type' => 'string'],
                'since_minutes' => ['required' => true, 'type' => 'integer'],
            ],
        ]);
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

    private static function checkToken(WP_REST_Request $req): ?WP_REST_Response
    {
        $userId = get_current_user_id();
        $token = (string) $req->get_param('session_token');
        if (!SessionToken::verify($token, $userId)) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'INVALID_OR_EXPIRED_TOKEN',
            ], 401);
        }
        return null;
    }

    public static function handleRecord(WP_REST_Request $req): WP_REST_Response
    {
        if (($bad = self::checkToken($req)) !== null) return $bad;

        $row = [
            'category' => (string) $req->get_param('category'),
            'subcategory' => (string) $req->get_param('subcategory'),
            'target_descriptor' => (string) $req->get_param('target_descriptor'),
            'before_state' => $req->get_param('before_state'),
            'after_state' => $req->get_param('after_state'),
            'reversible' => (bool) $req->get_param('reversible'),
            'source_tool' => $req->get_param('source_tool'),
            'source_session' => $req->get_param('source_session'),
            'notes' => $req->get_param('notes'),
        ];

        $auditId = ChangeRecorder::record($row);

        Log::append([
            'endpoint' => 'changes/record',
            'user' => (string) wp_get_current_user()->user_login,
            'site_url' => (string) get_option('siteurl'),
            'result' => 'success',
            'error' => null,
        ]);

        return new WP_REST_Response([
            'ok' => true,
            'audit_id' => $auditId,
        ], 200);
    }

    public static function handleQuery(WP_REST_Request $req): WP_REST_Response
    {
        if (($bad = self::checkToken($req)) !== null) return $bad;

        $filters = [];
        if ($req->get_param('category')) $filters['category'] = $req->get_param('category');
        if ($req->get_param('applied') !== null) {
            $filters['applied'] = filter_var($req->get_param('applied'), FILTER_VALIDATE_BOOLEAN);
        }
        if ($req->get_param('since_minutes')) $filters['since_minutes'] = (int) $req->get_param('since_minutes');
        if ($req->get_param('source_session')) $filters['source_session'] = $req->get_param('source_session');
        if ($req->get_param('limit')) $filters['limit'] = (int) $req->get_param('limit');

        $rows = ChangeRecorder::query($filters);

        return new WP_REST_Response([
            'ok' => true,
            'count' => count($rows),
            'rows' => $rows,
        ], 200);
    }

    public static function handleToggle(WP_REST_Request $req): WP_REST_Response
    {
        if (($bad = self::checkToken($req)) !== null) return $bad;

        $id = (int) $req->get_param('id');
        $applied = (bool) $req->get_param('applied');

        $row = ChangeRecorder::getById($id);
        if ($row === null) {
            return new WP_REST_Response(['ok' => false, 'error_code' => 'NOT_FOUND'], 404);
        }

        ChangeRecorder::setApplied($id, $applied);
        $row = ChangeRecorder::getById($id) ?? $row;

        $result = Toggler::apply($row, $applied);

        HookWrapper::flushCache();

        return new WP_REST_Response([
            'ok' => true,
            'id' => $id,
            'applied' => $applied,
            'side_effect' => $result,
        ], 200);
    }

    public static function handleToggleBulk(WP_REST_Request $req): WP_REST_Response
    {
        if (($bad = self::checkToken($req)) !== null) return $bad;

        $ids = (array) $req->get_param('ids');
        $applied = (bool) $req->get_param('applied');

        $results = [];
        foreach ($ids as $rawId) {
            $id = (int) $rawId;
            $row = ChangeRecorder::getById($id);
            if ($row === null) {
                $results[] = ['id' => $id, 'ok' => false, 'error' => 'NOT_FOUND'];
                continue;
            }
            ChangeRecorder::setApplied($id, $applied);
            $row = ChangeRecorder::getById($id) ?? $row;
            $side = Toggler::apply($row, $applied);
            $results[] = ['id' => $id, 'ok' => $side['ok'] ?? false, 'side_effect' => $side];
        }

        HookWrapper::flushCache();

        return new WP_REST_Response([
            'ok' => true,
            'applied' => $applied,
            'count' => count($results),
            'results' => $results,
        ], 200);
    }

    public static function handlePanic(WP_REST_Request $req): WP_REST_Response
    {
        if (($bad = self::checkToken($req)) !== null) return $bad;

        $sinceMinutes = max(1, min(1440, (int) $req->get_param('since_minutes')));
        $disabledIds = ChangeRecorder::panic($sinceMinutes);

        $sideEffects = [];
        foreach ($disabledIds as $id) {
            $row = ChangeRecorder::getById((int) $id);
            if ($row !== null) {
                $sideEffects[] = ['id' => (int) $id, 'side_effect' => Toggler::apply($row, false)];
            }
        }

        HookWrapper::flushCache();

        Log::append([
            'endpoint' => 'changes/panic',
            'user' => (string) wp_get_current_user()->user_login,
            'site_url' => (string) get_option('siteurl'),
            'result' => 'success',
            'error' => "disabled " . count($disabledIds) . " changes in last {$sinceMinutes}min",
        ]);

        return new WP_REST_Response([
            'ok' => true,
            'since_minutes' => $sinceMinutes,
            'disabled_count' => count($disabledIds),
            'disabled_ids' => $disabledIds,
            'side_effects' => $sideEffects,
        ], 200);
    }
}
