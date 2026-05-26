<?php
declare(strict_types=1);

namespace Rolepod\Wp\Admin;

use Rolepod\Wp\Audit\Log;
use Rolepod\Wp\Config;

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
