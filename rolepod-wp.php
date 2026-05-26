<?php
/**
 * Plugin Name:       Rolepod for WordPress
 * Plugin URI:        https://github.com/nuttaruj/rolepod-wp
 * Description:       The WordPress arm of the Rolepod ecosystem (https://github.com/nuttaruj/rolepod). Exposes guarded REST endpoints so AI coding agents (Claude Code / Cursor / Codex / Gemini) — driven by the rolepod-wplab MCP server — can run runtime introspection, the one-click pair wizard, and (with explicit opt-in) execute-php on this WordPress install. Endpoints are OFF by default; enable per-feature in Settings → Rolepod for WordPress.
 * Author:            nuttaruj
 * Author URI:        https://github.com/nuttaruj
 * Version:           2.3.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       rolepod-wp
 *
 * @package rolepod-wp
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('ROLEPOD_WP_VERSION', '2.3.1');
define('ROLEPOD_WP_FILE', __FILE__);
define('ROLEPOD_WP_DIR', plugin_dir_path(__FILE__));

// REST namespace intentionally retained as `wplab/v1` for backward compatibility
// with the MCP client which already pins this path. The plugin slug rename
// (v2.0.0) does not break the REST contract; only the install location +
// option keys + PHP namespace change.
define('ROLEPOD_WP_REST_NAMESPACE', 'wplab/v1');

// Manual PSR-4 autoload — no composer required.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Rolepod\\Wp\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = ROLEPOD_WP_DIR . 'src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

add_action('rest_api_init', static function (): void {
    \Rolepod\Wp\Endpoint\Handshake::register();
    \Rolepod\Wp\Endpoint\Introspect::register();
    \Rolepod\Wp\Endpoint\ExecutePhp::register();
    \Rolepod\Wp\Endpoint\WpCli::register();
    \Rolepod\Wp\Endpoint\FsRead::register();
    \Rolepod\Wp\Endpoint\FsWrite::register();
    \Rolepod\Wp\Endpoint\PhpSession::register();
    \Rolepod\Wp\Endpoint\RequestObserver::register();
    \Rolepod\Wp\Endpoint\Pair::register();
    // v2.3 — change ledger
    \Rolepod\Wp\Endpoint\Changes::register();
});

add_action('admin_menu', static function (): void {
    \Rolepod\Wp\Admin\SettingsPage::register();
    \Rolepod\Wp\Admin\SetupWizard::register();
    // v2.3 — AI Change Ledger
    \Rolepod\Wp\Admin\ChangeLedgerPage::register();
});

// v2.3 — auto-install ledger schema on upgrade. register_activation_hook
// only fires on fresh activate; WP plugin UPDATE replaces files without
// re-activating. This hook fires on every request once and is a no-op when
// the schema is already at the current version.
add_action('plugins_loaded', static function (): void {
    $current = (string) get_option(\Rolepod\Wp\Audit\ChangeLedger::TABLE_VERSION_OPTION, '');
    if ($current !== \Rolepod\Wp\Audit\ChangeLedger::TABLE_VERSION) {
        \Rolepod\Wp\Audit\ChangeLedger::install();
    }
}, 5);

register_activation_hook(__FILE__, static function (): void {
    if (!current_user_can('activate_plugins')) {
        return;
    }
    add_option('rolepod_wp_version', ROLEPOD_WP_VERSION);
    add_option('rolepod_wp_config', [
        'endpoints_enabled' => false, // OFF by default — admin must opt in
        'execute_php_enabled' => true, // ON by default once admin enables endpoints
        'production_hosts' => [], // glob patterns
    ]);
    // v2.3 — create the change-ledger table for AI-issued writes. Idempotent.
    \Rolepod\Wp\Audit\ChangeLedger::install();
});

register_deactivation_hook(__FILE__, static function (): void {
    // Plugin deactivated — endpoints unregister automatically since they're
    // bound on rest_api_init only when plugin is active. No state to clean
    // up here; uninstall.php handles option deletion.
});
