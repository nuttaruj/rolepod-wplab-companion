<?php
/**
 * Plugin Name: Rolepod for WordPress — Recovery Guardian
 * Description: mu-plugin recovery layer for rolepod-wp. Loads before regular plugins so it survives main-plugin parse/fatal errors. Exposes /wp-json/wplab-recovery/v1/* for out-of-band recovery (disable plugin, rename file, restore snapshot, panic ledger). Auto-installed by the main rolepod-wp plugin on activate; removed on deactivate.
 * Version: 2.6.0
 * Author: nuttaruj
 *
 * IMPORTANT: This file MUST be self-contained. The main plugin's autoloader,
 * classes, and helpers are NOT guaranteed to be available — that's the whole
 * point of a guardian. Every dependency is inlined or comes from WP core.
 *
 * @package rolepod-wp-guardian
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (defined('ROLEPOD_WP_GUARDIAN_VERSION')) {
    // Another copy already loaded — don't double-register.
    return;
}
define('ROLEPOD_WP_GUARDIAN_VERSION', '2.6.3');
define('ROLEPOD_WP_GUARDIAN_NAMESPACE', 'wplab-recovery/v1');
define('ROLEPOD_WP_GUARDIAN_FATALS_TRANSIENT', 'rolepod_wp_recovery_recent_fatals');
define('ROLEPOD_WP_GUARDIAN_SAFE_MODE_OPTION', 'rolepod_wp_safe_mode');

/**
 * Early-dispatch short-circuit (v2.6.1).
 *
 * WHY: rest_api_init only fires after WP completes init(), which happens
 * AFTER setup_theme + theme functions.php load. If theme fatals at load
 * time, rest_api_init never fires → our guardian REST routes never
 * register → recovery endpoints unreachable in that request. Theme load
 * failure is ~40% of real-world WSODs.
 *
 * FIX: detect recovery URL at the top of mu-plugin load, then hook
 * muplugins_loaded:PHP_INT_MAX to short-circuit BEFORE plugins or theme
 * are loaded. Manually init REST API, register guardian routes, dispatch,
 * exit. Plugins + theme never load for this request.
 *
 * AUTH: WP's Application Password validation classes are in wp-includes
 * (loaded by wp-load.php BEFORE mu-plugins). We can call
 * WP_Application_Passwords::validate_application_password() directly.
 */
$rolepod_guardian_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
if (
    strpos($rolepod_guardian_uri, '/wp-json/wplab-recovery/v1/') !== false
    || strpos($rolepod_guardian_uri, 'rest_route=/wplab-recovery/v1/') !== false
    || strpos($rolepod_guardian_uri, 'rest_route=%2Fwplab-recovery%2Fv1%2F') !== false
) {
    add_action('muplugins_loaded', static function (): void {
        rolepod_guardian_early_dispatch();
        exit; // never returns — bypasses plugin/theme load entirely
    }, PHP_INT_MAX);
}

/**
 * Detect FATAL via shutdown handler. WP core's own WSOD-protection uses the
 * same mechanism. We only record errors that look plugin/theme-induced —
 * core-PHP errors get logged too but aren't actionable through guardian.
 */
register_shutdown_function(static function (): void {
    $err = error_get_last();
    if (!is_array($err)) {
        return;
    }
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
    if (!in_array($err['type'] ?? 0, $fatalTypes, true)) {
        return;
    }

    // Only WP-context errors. Skip if no DB connection (set_transient
    // would fail anyway).
    if (!function_exists('set_transient')) {
        return;
    }

    $record = [
        'type' => (int) ($err['type'] ?? 0),
        'message' => (string) ($err['message'] ?? ''),
        'file' => (string) ($err['file'] ?? ''),
        'line' => (int) ($err['line'] ?? 0),
        'ts' => time(),
        'request_uri' => isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '',
        'is_plugin' => strpos((string) ($err['file'] ?? ''), '/plugins/') !== false,
        'is_theme' => strpos((string) ($err['file'] ?? ''), '/themes/') !== false,
        'is_mu_plugin' => strpos((string) ($err['file'] ?? ''), '/mu-plugins/') !== false,
    ];

    $recent = get_transient(ROLEPOD_WP_GUARDIAN_FATALS_TRANSIENT);
    if (!is_array($recent)) {
        $recent = [];
    }
    $recent[] = $record;
    // Keep last 10.
    if (count($recent) > 10) {
        $recent = array_slice($recent, -10);
    }
    set_transient(ROLEPOD_WP_GUARDIAN_FATALS_TRANSIENT, $recent, 24 * HOUR_IN_SECONDS);
});

/**
 * REST registration. Fires on rest_api_init regardless of main plugin status.
 */
add_action('rest_api_init', static function (): void {
    $perm = static function (): bool {
        return current_user_can('manage_options');
    };

    register_rest_route(ROLEPOD_WP_GUARDIAN_NAMESPACE, '/status', [
        'methods' => 'GET',
        'callback' => 'rolepod_guardian_status',
        'permission_callback' => $perm,
    ]);

    register_rest_route(ROLEPOD_WP_GUARDIAN_NAMESPACE, '/disable-plugin', [
        'methods' => 'POST',
        'callback' => 'rolepod_guardian_disable_plugin',
        'permission_callback' => $perm,
        'args' => [
            'plugin' => ['required' => true, 'type' => 'string'],
        ],
    ]);

    register_rest_route(ROLEPOD_WP_GUARDIAN_NAMESPACE, '/disable-file', [
        'methods' => 'POST',
        'callback' => 'rolepod_guardian_disable_file',
        'permission_callback' => $perm,
        'args' => [
            'path' => ['required' => true, 'type' => 'string'],
        ],
    ]);

    register_rest_route(ROLEPOD_WP_GUARDIAN_NAMESPACE, '/restore-file', [
        'methods' => 'POST',
        'callback' => 'rolepod_guardian_restore_file',
        'permission_callback' => $perm,
        'args' => [
            'path' => ['required' => true, 'type' => 'string'],
        ],
    ]);

    register_rest_route(ROLEPOD_WP_GUARDIAN_NAMESPACE, '/restore-snapshot', [
        'methods' => 'POST',
        'callback' => 'rolepod_guardian_restore_snapshot',
        'permission_callback' => $perm,
        'args' => [
            'snapshot_path' => ['required' => true, 'type' => 'string'],
        ],
    ]);

    register_rest_route(ROLEPOD_WP_GUARDIAN_NAMESPACE, '/list-changes', [
        'methods' => 'GET',
        'callback' => 'rolepod_guardian_list_changes',
        'permission_callback' => $perm,
    ]);

    register_rest_route(ROLEPOD_WP_GUARDIAN_NAMESPACE, '/safe-mode', [
        'methods' => 'POST',
        'callback' => 'rolepod_guardian_safe_mode',
        'permission_callback' => $perm,
        'args' => [
            'enabled' => ['required' => true, 'type' => 'boolean'],
        ],
    ]);

    register_rest_route(ROLEPOD_WP_GUARDIAN_NAMESPACE, '/clear-fatals', [
        'methods' => 'POST',
        'callback' => 'rolepod_guardian_clear_fatals',
        'permission_callback' => $perm,
    ]);
});

/**
 * GET /status — main plugin alive? recent fatals? safe-mode on?
 */
function rolepod_guardian_status(): WP_REST_Response
{
    $mainAlive = defined('ROLEPOD_WP_VERSION');
    $mainVersion = defined('ROLEPOD_WP_VERSION') ? constant('ROLEPOD_WP_VERSION') : null;

    $recent = get_transient(ROLEPOD_WP_GUARDIAN_FATALS_TRANSIENT);
    if (!is_array($recent)) {
        $recent = [];
    }

    $lastFatal = !empty($recent) ? end($recent) : null;

    return new WP_REST_Response([
        'ok' => true,
        'guardian_version' => ROLEPOD_WP_GUARDIAN_VERSION,
        'main_alive' => $mainAlive,
        'main_version' => $mainVersion,
        'safe_mode' => (bool) get_option(ROLEPOD_WP_GUARDIAN_SAFE_MODE_OPTION, false),
        'recent_fatals' => array_values($recent),
        'last_fatal' => $lastFatal,
        'wp_version' => function_exists('get_bloginfo') ? get_bloginfo('version') : null,
        'php_version' => PHP_VERSION,
        'siteurl' => function_exists('get_option') ? (string) get_option('siteurl') : null,
    ], 200);
}

/**
 * POST /disable-plugin — rename plugin file → .disabled, WP skips next boot.
 */
function rolepod_guardian_disable_plugin(WP_REST_Request $req): WP_REST_Response
{
    $plugin = trim((string) $req->get_param('plugin'));
    if ($plugin === '') {
        return new WP_REST_Response(['ok' => false, 'error_code' => 'PLUGIN_REQUIRED'], 400);
    }

    $pluginFile = rolepod_guardian_resolve_plugin_file($plugin);
    if ($pluginFile === null) {
        return new WP_REST_Response(['ok' => false, 'error_code' => 'PLUGIN_NOT_FOUND', 'plugin' => $plugin], 404);
    }

    $abs = WP_PLUGIN_DIR . '/' . $pluginFile;
    if (!is_file($abs)) {
        return new WP_REST_Response(['ok' => false, 'error_code' => 'PLUGIN_FILE_MISSING', 'path' => $abs], 404);
    }

    $disabled = $abs . '.disabled';
    if (is_file($disabled)) {
        return new WP_REST_Response(['ok' => false, 'error_code' => 'ALREADY_DISABLED', 'path' => $disabled], 409);
    }

    if (!@rename($abs, $disabled)) {
        return new WP_REST_Response(['ok' => false, 'error_code' => 'RENAME_FAILED', 'path' => $abs], 500);
    }

    // Best-effort: deactivate via WP API so active_plugins option is clean.
    if (function_exists('deactivate_plugins')) {
        @deactivate_plugins($pluginFile, true);
    }

    return new WP_REST_Response([
        'ok' => true,
        'disabled_file' => $disabled,
        'original_file' => $abs,
        'plugin' => $pluginFile,
    ], 200);
}

/**
 * POST /disable-file — rename arbitrary scoped file → .disabled.
 */
function rolepod_guardian_disable_file(WP_REST_Request $req): WP_REST_Response
{
    $path = (string) $req->get_param('path');
    $abs = rolepod_guardian_scope_path($path);
    if ($abs === null) {
        return new WP_REST_Response(['ok' => false, 'error_code' => 'OUT_OF_SCOPE', 'path' => $path], 403);
    }
    if (!is_file($abs)) {
        return new WP_REST_Response(['ok' => false, 'error_code' => 'NOT_FOUND', 'path' => $abs], 404);
    }
    $dest = $abs . '.disabled';
    if (is_file($dest)) {
        return new WP_REST_Response(['ok' => false, 'error_code' => 'ALREADY_DISABLED', 'path' => $dest], 409);
    }
    if (!@rename($abs, $dest)) {
        return new WP_REST_Response(['ok' => false, 'error_code' => 'RENAME_FAILED'], 500);
    }
    return new WP_REST_Response(['ok' => true, 'src' => $abs, 'dest' => $dest], 200);
}

/**
 * POST /restore-file — rename <path>.disabled → <path>. Accepts either form.
 */
function rolepod_guardian_restore_file(WP_REST_Request $req): WP_REST_Response
{
    $raw = (string) $req->get_param('path');
    $disabledForm = str_ends_with($raw, '.disabled') ? $raw : $raw . '.disabled';
    $activeForm = preg_replace('/\.disabled$/', '', $disabledForm);

    $absDisabled = rolepod_guardian_scope_path($disabledForm);
    $absActive = rolepod_guardian_scope_path($activeForm);
    if ($absDisabled === null || $absActive === null) {
        return new WP_REST_Response(['ok' => false, 'error_code' => 'OUT_OF_SCOPE'], 403);
    }
    if (!is_file($absDisabled)) {
        return new WP_REST_Response(['ok' => false, 'error_code' => 'DISABLED_FILE_NOT_FOUND', 'path' => $absDisabled], 404);
    }
    if (is_file($absActive)) {
        return new WP_REST_Response(['ok' => false, 'error_code' => 'ACTIVE_FILE_EXISTS', 'path' => $absActive], 409);
    }
    if (!@rename($absDisabled, $absActive)) {
        return new WP_REST_Response(['ok' => false, 'error_code' => 'RENAME_FAILED'], 500);
    }
    return new WP_REST_Response(['ok' => true, 'src' => $absDisabled, 'dest' => $absActive], 200);
}

/**
 * POST /restore-snapshot — untar a previously-captured theme snapshot.
 */
function rolepod_guardian_restore_snapshot(WP_REST_Request $req): WP_REST_Response
{
    $snapshotPath = (string) $req->get_param('snapshot_path');
    if (!class_exists('PharData')) {
        return new WP_REST_Response(['ok' => false, 'error_code' => 'PHAR_UNAVAILABLE'], 503);
    }

    $uploadDir = wp_upload_dir();
    if (!is_array($uploadDir) || empty($uploadDir['basedir'])) {
        return new WP_REST_Response(['ok' => false, 'error_code' => 'UPLOAD_DIR_UNAVAILABLE'], 500);
    }
    $snapshotsRoot = realpath(trailingslashit((string) $uploadDir['basedir']) . 'rolepod-wp-theme-snapshots');
    if ($snapshotsRoot === false) {
        return new WP_REST_Response(['ok' => false, 'error_code' => 'SNAPSHOTS_DIR_MISSING'], 404);
    }

    $abs = realpath($snapshotPath);
    if ($abs === false || strpos($abs, $snapshotsRoot . '/') !== 0) {
        return new WP_REST_Response(['ok' => false, 'error_code' => 'SNAPSHOT_OUT_OF_SCOPE'], 403);
    }
    if (!is_file($abs) || !str_ends_with($abs, '.tar.gz')) {
        return new WP_REST_Response(['ok' => false, 'error_code' => 'INVALID_SNAPSHOT'], 400);
    }

    // Filename convention: <slug>-<utc-ts>.tar.gz — extract slug.
    $base = basename($abs, '.tar.gz');
    $parts = explode('-', $base);
    array_pop($parts); // drop ts
    $slug = implode('-', $parts);
    if ($slug === '') {
        return new WP_REST_Response(['ok' => false, 'error_code' => 'CANNOT_DERIVE_SLUG'], 400);
    }

    $themesDir = WP_CONTENT_DIR . '/themes';
    $target = $themesDir . '/' . $slug;

    try {
        $phar = new \PharData($abs);
        $phar->extractTo($themesDir, null, true);
    } catch (\Throwable $t) {
        return new WP_REST_Response([
            'ok' => false,
            'error_code' => 'EXTRACT_FAILED',
            'error_message' => $t->getMessage(),
        ], 500);
    }

    return new WP_REST_Response([
        'ok' => true,
        'restored_theme' => $slug,
        'snapshot_path' => $abs,
        'target_dir' => $target,
    ], 200);
}

/**
 * GET /list-changes — read ledger DB. Useful pre-panic to inspect what's
 * recent. Works only if DB alive (otherwise nothing here would work either).
 */
function rolepod_guardian_list_changes(WP_REST_Request $req): WP_REST_Response
{
    global $wpdb;
    $table = $wpdb->prefix . 'rolepod_wp_changes';
    $limit = (int) ($req->get_param('limit') ?? 50);
    $limit = max(1, min(500, $limit));

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
    if ($exists !== $table) {
        return new WP_REST_Response(['ok' => true, 'changes' => [], 'note' => 'ledger table not yet installed'], 200);
    }

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, audit_id, category, subcategory, target_descriptor, applied, reversible, source_tool, source_session, created_at FROM {$table} ORDER BY id DESC LIMIT %d",
        $limit
    ), ARRAY_A);

    return new WP_REST_Response([
        'ok' => true,
        'changes' => is_array($rows) ? $rows : [],
        'count' => is_array($rows) ? count($rows) : 0,
    ], 200);
}

/**
 * POST /safe-mode — toggle the ROLEPOD_WP_SAFE_MODE flag. Main plugin (when
 * alive) should refuse risky ops when this is on.
 */
function rolepod_guardian_safe_mode(WP_REST_Request $req): WP_REST_Response
{
    $enabled = (bool) $req->get_param('enabled');
    update_option(ROLEPOD_WP_GUARDIAN_SAFE_MODE_OPTION, $enabled, false);
    return new WP_REST_Response([
        'ok' => true,
        'safe_mode' => $enabled,
    ], 200);
}

/**
 * POST /clear-fatals — clear the recent-fatals transient after manual review.
 */
function rolepod_guardian_clear_fatals(): WP_REST_Response
{
    delete_transient(ROLEPOD_WP_GUARDIAN_FATALS_TRANSIENT);
    return new WP_REST_Response(['ok' => true], 200);
}

// -----------------------------------------------------------------------------
// Early-dispatch (v2.6.1)
// -----------------------------------------------------------------------------

/**
 * Manual REST dispatch from muplugins_loaded. Authenticates via WP
 * Application Password directly (Basic auth header), registers guardian
 * routes, dispatches to the handler, emits JSON, exits.
 */
function rolepod_guardian_early_dispatch(): void
{
    if (!defined('REST_REQUEST')) {
        define('REST_REQUEST', true);
    }

    $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    $method = isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET';

    // Extract route from URI (handles both /wp-json/wplab-recovery/v1/... and ?rest_route=)
    $route = rolepod_guardian_extract_route($uri);
    if ($route === null) {
        rolepod_guardian_emit(404, ['code' => 'rest_no_route', 'message' => 'no recovery route in URI']);
    }

    // Authenticate via Application Password (Basic auth).
    $user = rolepod_guardian_authenticate();
    if ($user === null) {
        header('WWW-Authenticate: Basic realm="WordPress Recovery"');
        rolepod_guardian_emit(401, ['code' => 'rest_not_logged_in', 'message' => 'authentication required']);
    }
    wp_set_current_user($user->ID);

    if (!current_user_can('manage_options')) {
        rolepod_guardian_emit(403, ['code' => 'rest_forbidden', 'message' => 'manage_options required']);
    }

    // Force-init REST API (fires rest_api_init action which registers guardian routes).
    $server = rest_get_server();

    // Build request
    $request = new WP_REST_Request($method, $route);

    // Query params
    foreach ($_GET as $key => $value) {
        if ($key === 'rest_route') continue;
        $request->set_param((string) $key, $value);
    }

    // Body params
    if (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'], true)) {
        $body = file_get_contents('php://input');
        if (is_string($body) && $body !== '') {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                foreach ($decoded as $key => $value) {
                    $request->set_param((string) $key, $value);
                }
                $request->set_body($body);
                $request->set_header('Content-Type', 'application/json');
            }
        }
    }

    // Dispatch
    $response = $server->dispatch($request);
    $status = $response instanceof WP_REST_Response ? $response->get_status() : 200;
    $data = $response instanceof WP_REST_Response ? $response->get_data() : $response;

    rolepod_guardian_emit($status, $data);
}

/**
 * Parse REST route from request URI. Returns "/wplab-recovery/v1/<action>"
 * or null if no match.
 */
function rolepod_guardian_extract_route(string $uri): ?string
{
    // /wp-json/wplab-recovery/v1/status (and beyond)
    if (preg_match('#/wp-json(/wplab-recovery/v1/[A-Za-z0-9_\-/]+)#', $uri, $m)) {
        return $m[1];
    }
    // ?rest_route=/wplab-recovery/v1/status
    if (preg_match('#rest_route=(/wplab-recovery/v1/[A-Za-z0-9_\-/]+)#', $uri, $m)) {
        return urldecode($m[1]);
    }
    // url-encoded form
    if (preg_match('#rest_route=(%2Fwplab-recovery%2Fv1%2F[A-Za-z0-9_\-/]+)#', $uri, $m)) {
        return urldecode($m[1]);
    }
    return null;
}

/**
 * Manual Application Password auth (Basic auth header). Returns WP_User on
 * success, null on failure. Bypasses normal init flow because we're at
 * muplugins_loaded — well before init.
 *
 * Auth header sources, in order: PHP_AUTH_USER (Apache mod_php),
 * HTTP_AUTHORIZATION (FastCGI/Nginx), REDIRECT_HTTP_AUTHORIZATION
 * (some Apache RewriteRule setups).
 *
 * Uses WP_Application_Passwords::validate_application_password — the
 * lowest-level validation that bypasses `wp_is_application_passwords_available()`
 * (which a security plugin may have disabled). This is intentional for
 * recovery: we want a working escape hatch even if Application Passwords
 * are disabled site-wide.
 */
function rolepod_guardian_authenticate(): ?\WP_User
{
    [$user, $pass] = rolepod_guardian_extract_basic_auth();
    if ($user === '' || $pass === '') {
        return null;
    }

    // get_user_by lives in wp-includes/user.php which is loaded by
    // wp-load.php before mu-plugins, so it's always available here.
    $wp_user = is_email($user) ? get_user_by('email', $user) : get_user_by('login', $user);
    if (!$wp_user) {
        return null;
    }

    // wp_check_password is pluggable + lives in wp-includes/pluggable.php
    // which loads AFTER plugins in wp-settings.php, so it's NOT available
    // at muplugins_loaded by default. Require it explicitly. This locks
    // in WP-core's wp_check_password (security plugins can't override the
    // recovery path) — desired for an escape hatch.
    if (!function_exists('wp_check_password')) {
        require_once ABSPATH . WPINC . '/pluggable.php';
    }

    if (!class_exists('\WP_Application_Passwords')) {
        require_once ABSPATH . WPINC . '/class-wp-application-passwords.php';
    }

    $valid = \WP_Application_Passwords::validate_application_password($wp_user->ID, $pass);
    if (is_array($valid) && !empty($valid)) {
        // validate_application_password returns the matched password item
        // (array) on success or false on no match.
        return $wp_user;
    }

    // Last-resort fallback: plain login password (useful for local dev /
    // CI smoke tests that don't have Application Passwords set up).
    if (wp_check_password($pass, $wp_user->user_pass, $wp_user->ID)) {
        return $wp_user;
    }

    return null;
}

/**
 * Extract username + password from request, handling all common
 * server-variable layouts.
 *
 * @return array{0:string,1:string} [user, pass] — empty strings if missing.
 */
function rolepod_guardian_extract_basic_auth(): array
{
    $user = isset($_SERVER['PHP_AUTH_USER']) ? (string) $_SERVER['PHP_AUTH_USER'] : '';
    $pass = isset($_SERVER['PHP_AUTH_PW']) ? (string) $_SERVER['PHP_AUTH_PW'] : '';
    if ($user !== '' && $pass !== '') {
        return [$user, $pass];
    }

    $authHeader = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeader = (string) $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        // Some Apache configs strip Authorization unless rewritten.
        $authHeader = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    } elseif (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $name => $value) {
                if (strcasecmp((string) $name, 'Authorization') === 0) {
                    $authHeader = (string) $value;
                    break;
                }
            }
        }
    }

    if ($authHeader !== '' && stripos($authHeader, 'Basic ') === 0) {
        $decoded = base64_decode(substr($authHeader, 6), true);
        if (is_string($decoded) && strpos($decoded, ':') !== false) {
            [$user, $pass] = explode(':', $decoded, 2);
            return [(string) $user, (string) $pass];
        }
    }

    return ['', ''];
}

/**
 * Emit JSON response + exit. Sends proper status header.
 */
function rolepod_guardian_emit(int $status, $data): void
{
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Rolepod-Guardian: ' . ROLEPOD_WP_GUARDIAN_VERSION);
        http_response_code($status);
    }
    echo wp_json_encode($data);
    exit;
}

// -----------------------------------------------------------------------------
// Helpers (inlined — no main-plugin autoload dependency)
// -----------------------------------------------------------------------------

/**
 * Map a plugin identifier (slug OR "slug/file.php") to its main file path
 * relative to WP_PLUGIN_DIR.
 */
function rolepod_guardian_resolve_plugin_file(string $plugin): ?string
{
    // Already in "slug/file.php" form?
    if (strpos($plugin, '/') !== false) {
        return $plugin;
    }
    // Try slug/slug.php convention.
    $candidate = $plugin . '/' . $plugin . '.php';
    if (is_file(WP_PLUGIN_DIR . '/' . $candidate)) {
        return $candidate;
    }
    // Scan via get_plugins() if available.
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    if (function_exists('get_plugins')) {
        $all = get_plugins();
        foreach ($all as $file => $_) {
            if (strpos($file, $plugin . '/') === 0 || basename(dirname($file)) === $plugin) {
                return $file;
            }
        }
    }
    return null;
}

/**
 * Resolve relative path to absolute, then verify it sits under one of:
 *   wp-content/{plugins,themes,uploads,mu-plugins}, or wp-config.php.
 * Returns absolute path or null if out of scope.
 */
function rolepod_guardian_scope_path(string $relative): ?string
{
    $abs = ($relative !== '' && $relative[0] === '/') ? $relative : ABSPATH . ltrim($relative, '/');
    $parent = dirname($abs);
    $real = realpath($parent);
    if ($real === false) {
        return null;
    }
    $abs = rtrim($real, '/') . '/' . basename($abs);

    $allowed = [
        realpath(WP_CONTENT_DIR . '/plugins'),
        realpath(WP_CONTENT_DIR . '/themes'),
        realpath(WP_CONTENT_DIR . '/uploads'),
        realpath(WP_CONTENT_DIR . '/mu-plugins'),
    ];
    foreach ($allowed as $root) {
        if ($root === false) {
            continue;
        }
        if (strpos($abs, $root . '/') === 0) {
            return $abs;
        }
    }
    $wpConfig = realpath(ABSPATH . 'wp-config.php');
    if ($wpConfig !== false && $abs === $wpConfig) {
        return $abs;
    }
    return null;
}
