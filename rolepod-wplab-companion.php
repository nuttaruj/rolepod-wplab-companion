<?php
/**
 * Plugin Name:       Rolepod WPLab Companion
 * Plugin URI:        https://github.com/nuttaruj/rolepod-wplab-companion
 * Description:       Optional companion for rolepod-wplab. Exposes guarded REST endpoints so AI coding agents (Claude Code / Cursor / Codex / Gemini) can run runtime introspection (and, with explicit opt-in, execute-php) on this WordPress install. v0.1 ships execute-php DISABLED. Enable per-endpoint in Settings → WPLab Companion.
 * Author:            nuttaruj
 * Author URI:        https://github.com/nuttaruj
 * Version:           1.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       rolepod-wplab-companion
 *
 * @package rolepod-wplab-companion
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('ROLEPOD_WPLAB_COMPANION_VERSION', '1.2.0');
define('ROLEPOD_WPLAB_COMPANION_FILE', __FILE__);
define('ROLEPOD_WPLAB_COMPANION_DIR', plugin_dir_path(__FILE__));
define('ROLEPOD_WPLAB_COMPANION_NAMESPACE', 'wplab/v1');

// Manual PSR-4-style autoload — no composer required for v0.1.
spl_autoload_register(static function (string $class): void {
    $prefix = 'RolepodWplabCompanion\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = ROLEPOD_WPLAB_COMPANION_DIR . 'src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

add_action('rest_api_init', static function (): void {
    \RolepodWplabCompanion\Endpoint\Handshake::register();
    \RolepodWplabCompanion\Endpoint\Introspect::register();
    \RolepodWplabCompanion\Endpoint\ExecutePhp::register();
    // v0.2 endpoints
    \RolepodWplabCompanion\Endpoint\WpCli::register();
    \RolepodWplabCompanion\Endpoint\FsRead::register();
    \RolepodWplabCompanion\Endpoint\FsWrite::register();
    \RolepodWplabCompanion\Endpoint\PhpSession::register();
    \RolepodWplabCompanion\Endpoint\RequestObserver::register();
    // v1.2 — one-click pairing
    \RolepodWplabCompanion\Endpoint\Pair::register();
});

add_action('admin_menu', static function (): void {
    \RolepodWplabCompanion\Admin\SettingsPage::register();
    \RolepodWplabCompanion\Admin\SetupWizard::register();
});

register_activation_hook(__FILE__, static function (): void {
    if (!current_user_can('activate_plugins')) {
        return;
    }
    add_option('rolepod_wplab_companion_version', ROLEPOD_WPLAB_COMPANION_VERSION);
    add_option('rolepod_wplab_companion_config', [
        'endpoints_enabled' => false, // OFF by default — admin must opt in
        'execute_php_enabled' => true, // v0.2: ON by default once admin enables endpoints
        'production_hosts' => [], // glob patterns
    ]);
});

register_deactivation_hook(__FILE__, static function (): void {
    // Plugin deactivated — endpoints unregister automatically since they're
    // bound on rest_api_init only when plugin is active. No state to clean
    // up here; uninstall.php handles option deletion.
});
