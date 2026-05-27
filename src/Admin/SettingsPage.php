<?php
declare(strict_types=1);

namespace Rolepod\Wp\Admin;

use Rolepod\Wp\Audit\Log;
use Rolepod\Wp\Config;
use Rolepod\Wp\Guardian;

/**
 * Settings → Rolepod for WordPress (single admin page).
 *
 * - Master toggle (endpoints_enabled)
 * - execute-php toggle (execute_php_enabled, v0.1 default OFF)
 * - Production hostnames (comma-separated globs)
 * - Audit log tail (last 50 entries, links to full payload files)
 */
final class SettingsPage
{
    private const SLUG = 'rolepod-wp';
    private const NONCE_ACTION = 'rolepod_wp_save';

    public static function register(): void
    {
        add_options_page(
            'Rolepod for WordPress',
            'Rolepod for WordPress',
            'manage_options',
            self::SLUG,
            [self::class, 'render']
        );
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions.');
        }

        if (
            isset($_POST['rolepod_wp_save_nonce'])
            && wp_verify_nonce(
                sanitize_text_field((string) wp_unslash($_POST['rolepod_wp_save_nonce'])),
                self::NONCE_ACTION
            )
        ) {
            self::handleSave();
        }

        if (
            isset($_POST['rolepod_wp_guardian_nonce'])
            && wp_verify_nonce(
                sanitize_text_field((string) wp_unslash($_POST['rolepod_wp_guardian_nonce'])),
                self::NONCE_ACTION . '_guardian'
            )
        ) {
            self::handleGuardianAction();
        }

        $config = Config::all();
        $endpointsEnabled = (bool) ($config['endpoints_enabled'] ?? false);
        $executePhpEnabled = (bool) ($config['execute_php_enabled'] ?? false);
        $prodHosts = implode(', ', Config::productionHosts());

        ?>
        <div class="wrap">
            <h1>Rolepod for WordPress</h1>
            <p>Plugin v<?php echo esc_html(ROLEPOD_WP_VERSION); ?> — exposes guarded REST endpoints under <code>/wp-json/wplab/v1/</code> for use by the Node MCP (<a href="https://github.com/nuttaruj/rolepod-wplab" target="_blank" rel="noopener">rolepod-wplab</a>). Part of the <a href="https://github.com/nuttaruj/rolepod" target="_blank" rel="noopener">Rolepod ecosystem</a>.</p>

            <form method="post">
                <?php wp_nonce_field(self::NONCE_ACTION, 'rolepod_wp_save_nonce'); ?>

                <h2>Endpoint master toggle</h2>
                <p>
                    <label>
                        <input type="checkbox" name="endpoints_enabled" value="1" <?php checked($endpointsEnabled); ?>>
                        Enable companion REST endpoints
                    </label>
                </p>
                <p class="description">When OFF (default after install), all <code>/wplab/v1/*</code> endpoints return 403. Turn this ON only when you actively use the Node MCP.</p>

                <h2>execute-php (DANGEROUS)</h2>
                <p>
                    <label>
                        <input type="checkbox" name="execute_php_enabled" value="1" <?php checked($executePhpEnabled); ?>>
                        Enable <code>POST /execute-php</code>
                    </label>
                </p>
                <p class="description"><strong>v0.1 ships with this OFF.</strong> Even when ON, every call requires: a valid session token, an AST-screen-clean payload, and a non-production siteurl. Production-matched targets refuse regardless of this toggle.</p>

                <h2>Production hostnames</h2>
                <p>
                    <input type="text" name="production_hosts" value="<?php echo esc_attr($prodHosts); ?>" size="60" placeholder="e.g. mysite.com, *.client-prod.com">
                </p>
                <p class="description">Comma-separated glob patterns. If <code>siteurl</code> matches any of these, execute-php is unconditionally refused.</p>

                <?php submit_button('Save settings'); ?>
            </form>

            <hr>

            <h2>Recovery guardian (mu-plugin)</h2>
            <?php
                $guardianInstalled = Guardian::isInstalled();
                $guardianPath = Guardian::destinationPath();
                $safeMode = (bool) get_option('rolepod_wp_safe_mode', false);
                $recentFatals = get_transient('rolepod_wp_recovery_recent_fatals');
                if (!is_array($recentFatals)) {
                    $recentFatals = [];
                }
            ?>
            <p>
                Status:
                <?php if ($guardianInstalled): ?>
                    <strong style="color:#2271b1;">✔ Installed</strong> at <code><?php echo esc_html($guardianPath); ?></code>
                <?php else: ?>
                    <strong style="color:#b32d2e;">✘ Not installed</strong>
                <?php endif; ?>
            </p>
            <p class="description">The guardian is a tiny mu-plugin that loads <em>before</em> regular plugins. If the main rolepod-wp plugin (or any other plugin/theme) crashes with a parse error or fatal, the guardian's REST endpoints under <code>/wp-json/wplab-recovery/v1/*</code> stay reachable so the MCP can disable the offending file or restore a theme snapshot — without you needing SSH or FTP.</p>

            <form method="post" style="margin-top:8px;">
                <?php wp_nonce_field(self::NONCE_ACTION . '_guardian', 'rolepod_wp_guardian_nonce'); ?>
                <?php if ($guardianInstalled): ?>
                    <button type="submit" name="guardian_action" value="reinstall" class="button">Reinstall guardian</button>
                    <button type="submit" name="guardian_action" value="remove" class="button button-secondary" onclick="return confirm('Remove the recovery guardian? You lose crash-recovery REST endpoints until you reinstall.');">Remove guardian</button>
                <?php else: ?>
                    <button type="submit" name="guardian_action" value="install" class="button button-primary">Install guardian</button>
                <?php endif; ?>
            </form>

            <?php if ($safeMode): ?>
                <p style="margin-top:8px;"><strong style="color:#b32d2e;">⚠ Safe mode is ON.</strong> The MCP refuses risky ops (execute-php, theme switch, file write to functions.php / wp-config) until you clear it.</p>
                <form method="post" style="margin-top:4px;">
                    <?php wp_nonce_field(self::NONCE_ACTION . '_guardian', 'rolepod_wp_guardian_nonce'); ?>
                    <button type="submit" name="guardian_action" value="clear_safe_mode" class="button">Clear safe mode</button>
                </form>
            <?php endif; ?>

            <?php if (!empty($recentFatals)): ?>
                <h3 style="margin-top:16px;">Recent fatals (caught by guardian)</h3>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Time (UTC)</th>
                            <th>Type</th>
                            <th>File:Line</th>
                            <th>Message</th>
                            <th>URI</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_reverse($recentFatals) as $fatal): ?>
                            <tr>
                                <td><?php echo esc_html(gmdate('Y-m-d H:i:s', (int) ($fatal['ts'] ?? 0))); ?></td>
                                <td><code><?php echo esc_html(self::errorTypeName((int) ($fatal['type'] ?? 0))); ?></code></td>
                                <td><code><?php echo esc_html((string) ($fatal['file'] ?? '')); ?>:<?php echo (int) ($fatal['line'] ?? 0); ?></code></td>
                                <td><?php echo esc_html((string) ($fatal['message'] ?? '')); ?></td>
                                <td><code><?php echo esc_html((string) ($fatal['request_uri'] ?? '')); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <form method="post" style="margin-top:8px;">
                    <?php wp_nonce_field(self::NONCE_ACTION . '_guardian', 'rolepod_wp_guardian_nonce'); ?>
                    <button type="submit" name="guardian_action" value="clear_fatals" class="button">Clear fatal log</button>
                </form>
            <?php endif; ?>

            <hr>

            <h2>Audit log (last 50 entries)</h2>
            <?php $tail = Log::tail(50); ?>
            <?php if (count($tail) === 0): ?>
                <p><em>No audit entries yet.</em></p>
            <?php else: ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Time (UTC)</th>
                            <th>Endpoint</th>
                            <th>User</th>
                            <th>Result</th>
                            <th>Error</th>
                            <th>Audit ID</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tail as $row): ?>
                            <tr>
                                <td><?php echo esc_html((string) ($row['timestamp'] ?? '')); ?></td>
                                <td><?php echo esc_html((string) ($row['endpoint'] ?? '')); ?></td>
                                <td><?php echo esc_html((string) ($row['user'] ?? '')); ?></td>
                                <td><?php echo esc_html((string) ($row['result'] ?? '')); ?></td>
                                <td><?php echo esc_html((string) ($row['error'] ?? '')); ?></td>
                                <td><code><?php echo esc_html((string) ($row['audit_id'] ?? '')); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="description">Full payloads (for execute-php calls) are at <code>wp-content/uploads/rolepod-wp-audit/&lt;audit_id&gt;.log</code> (mode 0600). Append-only — never modified after write.</p>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function handleGuardianAction(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $action = isset($_POST['guardian_action'])
            ? sanitize_text_field((string) wp_unslash($_POST['guardian_action']))
            : '';

        switch ($action) {
            case 'install':
            case 'reinstall':
                $result = Guardian::install();
                if ($result['ok']) {
                    echo '<div class="notice notice-success is-dismissible"><p>Guardian installed at <code>' . esc_html((string) $result['path']) . '</code>.</p></div>';
                } else {
                    echo '<div class="notice notice-error"><p>Guardian install failed: <code>' . esc_html((string) ($result['error'] ?? 'UNKNOWN')) . '</code> at <code>' . esc_html((string) $result['path']) . '</code>.</p></div>';
                }
                break;
            case 'remove':
                Guardian::remove();
                echo '<div class="notice notice-warning is-dismissible"><p>Guardian removed. Reinstall to restore crash-recovery endpoints.</p></div>';
                break;
            case 'clear_fatals':
                delete_transient('rolepod_wp_recovery_recent_fatals');
                echo '<div class="notice notice-success is-dismissible"><p>Recent fatal log cleared.</p></div>';
                break;
            case 'clear_safe_mode':
                update_option('rolepod_wp_safe_mode', false, false);
                echo '<div class="notice notice-success is-dismissible"><p>Safe mode cleared.</p></div>';
                break;
        }
    }

    private static function errorTypeName(int $type): string
    {
        $map = [
            E_ERROR => 'E_ERROR',
            E_PARSE => 'E_PARSE',
            E_CORE_ERROR => 'E_CORE_ERROR',
            E_COMPILE_ERROR => 'E_COMPILE_ERROR',
            E_USER_ERROR => 'E_USER_ERROR',
            E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
        ];
        return $map[$type] ?? ('E_' . $type);
    }

    private static function handleSave(): void
    {
        $endpointsEnabled = isset($_POST['endpoints_enabled']);
        $executePhpEnabled = isset($_POST['execute_php_enabled']);
        $rawProdHosts = isset($_POST['production_hosts'])
            ? sanitize_text_field((string) wp_unslash($_POST['production_hosts']))
            : '';
        $prodHosts = array_values(array_filter(
            array_map('trim', explode(',', $rawProdHosts)),
            'strlen'
        ));

        Config::update([
            'endpoints_enabled' => $endpointsEnabled,
            'execute_php_enabled' => $executePhpEnabled,
            'production_hosts' => $prodHosts,
        ]);

        echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
    }
}
