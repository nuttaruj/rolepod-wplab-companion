<?php
/**
 * Plugin Name:       Rolepod WPLab Companion
 * Plugin URI:        https://github.com/nuttaruj/rolepod-wplab-companion
 * Description:       Optional companion for rolepod-wplab. Exposes guarded REST endpoints so AI coding agents (Claude Code / Cursor / Codex / Gemini) can run execute-php + runtime introspection on this WordPress install. NEVER install on a production site without explicit need. Default-disabled until you enable endpoints in Settings → WPLab Companion.
 * Author:            nuttaruj
 * Author URI:        https://github.com/nuttaruj
 * Version:           0.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       rolepod-wplab-companion
 *
 * @package           rolepod-wplab-companion
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('ROLEPOD_WPLAB_COMPANION_VERSION', '0.0.0');
define('ROLEPOD_WPLAB_COMPANION_FILE', __FILE__);
define('ROLEPOD_WPLAB_COMPANION_DIR', plugin_dir_path(__FILE__));

// v0.1 will register REST routes + admin page here.
// v0.0 = scaffold only — no endpoints, no admin page, no eval.
//
// On activation: do nothing yet (deferred to v0.1).
register_activation_hook(__FILE__, static function () {
    if (!current_user_can('activate_plugins')) {
        return;
    }
    // Mark a flag so admin can see scaffold version is installed.
    add_option('rolepod_wplab_companion_version', ROLEPOD_WPLAB_COMPANION_VERSION);
});

register_deactivation_hook(__FILE__, static function () {
    // No state to clean up at v0.0.
});
