<?php
declare(strict_types=1);

namespace Rolepod\Wp\Admin;

use Rolepod\Wp\Audit\Log;
use Rolepod\Wp\Config;
use Rolepod\Wp\Guardian;

/**
 * Settings page — top-level submenu "Settings".
 *
 * - Master toggle (endpoints_enabled)
 * - execute-php toggle
 * - Production hostnames
 * - Recovery guardian status + actions
 * - Audit log tail (last 50 entries) as a timeline
 */
final class SettingsPage
{
    private const NONCE_ACTION = 'rolepod_wp_save';

    public static function register(): void { /* registered via Menu::register() */ }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions.');
        }

        $savedNotice = null;
        if (
            isset($_POST['rolepod_wp_save_nonce'])
            && wp_verify_nonce(
                sanitize_text_field((string) wp_unslash($_POST['rolepod_wp_save_nonce'])),
                self::NONCE_ACTION
            )
        ) {
            self::handleSave();
            $savedNotice = 'Settings saved.';
        }

        $guardianNotice = null;
        if (
            isset($_POST['rolepod_wp_guardian_nonce'])
            && wp_verify_nonce(
                sanitize_text_field((string) wp_unslash($_POST['rolepod_wp_guardian_nonce'])),
                self::NONCE_ACTION . '_guardian'
            )
        ) {
            $guardianNotice = self::handleGuardianAction();
        }

        $config = Config::all();
        $endpointsEnabled = (bool) ($config['endpoints_enabled'] ?? false);
        $executePhpEnabled = (bool) ($config['execute_php_enabled'] ?? false);
        $prodHosts = implode(', ', Config::productionHosts());

        $guardianInstalled = Guardian::isInstalled();
        $guardianPath = Guardian::destinationPath();
        $safeMode = (bool) get_option('rolepod_wp_safe_mode', false);
        $recentFatals = get_transient('rolepod_wp_recovery_recent_fatals');
        if (!is_array($recentFatals)) {
            $recentFatals = [];
        }

        Shell::open(Menu::SLUG_SETTINGS);

        if ($savedNotice !== null) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($savedNotice) . '</p></div>';
        }
        if ($guardianNotice !== null) {
            echo '<div class="notice notice-' . esc_attr($guardianNotice['type']) . ' is-dismissible"><p>' . wp_kses_post($guardianNotice['message']) . '</p></div>';
        }
        if ($safeMode) {
            echo '<div class="notice notice-warning"><p><strong>Safe mode is ON.</strong> The MCP refuses risky ops (execute-php, theme switch, file write to functions.php / wp-config) until cleared.</p>';
            echo '<form method="post" style="margin-top:8px;">';
            wp_nonce_field(self::NONCE_ACTION . '_guardian', 'rolepod_wp_guardian_nonce');
            echo '<button type="submit" name="guardian_action" value="clear_safe_mode" class="rp-btn">Clear safe mode</button>';
            echo '</form></div>';
        }

        ?>

        <div class="rp-grid-main">
            <div>

                <!-- Security & endpoints -->
                <form method="post">
                    <?php wp_nonce_field(self::NONCE_ACTION, 'rolepod_wp_save_nonce'); ?>

                    <div class="rp-card">
                        <div class="rp-card-head">
                            <div>
                                <h3>Security &amp; endpoints</h3>
                                <div class="rp-sub">Master controls for the companion REST surface.</div>
                            </div>
                            <span class="rp-badge rp-badge-neutral">Guarded</span>
                        </div>
                        <div class="rp-card-pad" style="padding-top:4px;padding-bottom:4px;">

                            <div class="rp-toggle-row <?php echo $endpointsEnabled ? 'is-on' : ''; ?>">
                                <div class="rp-toggle-icon"><?php echo self::iconPlug(); ?></div>
                                <div class="rp-toggle-body">
                                    <strong>Enable companion REST endpoints</strong>
                                    <div class="rp-desc">Master toggle for the <code>/wplab/v1/*</code> surface. When OFF every endpoint returns <code>403</code> regardless of credentials. Turn ON only when you actively use the Node MCP.</div>
                                </div>
                                <label class="rp-toggle">
                                    <input type="checkbox" name="endpoints_enabled" value="1" <?php checked($endpointsEnabled); ?>>
                                    <span class="rp-toggle-track"></span>
                                </label>
                            </div>

                            <div class="rp-toggle-row <?php echo $executePhpEnabled ? 'is-danger' : ''; ?>">
                                <div class="rp-toggle-icon"><?php echo self::iconCode(); ?></div>
                                <div class="rp-toggle-body">
                                    <strong>Enable <code>POST /execute-php</code> <span class="rp-badge rp-badge-danger" style="margin-left:6px;">Dangerous</span></strong>
                                    <div class="rp-desc">Even when ON, every call requires a valid session token, an AST-screen-clean payload, and a non-production siteurl. Production-matched targets refuse regardless of this toggle.</div>
                                </div>
                                <label class="rp-toggle">
                                    <input type="checkbox" name="execute_php_enabled" value="1" <?php checked($executePhpEnabled); ?>>
                                    <span class="rp-toggle-track"></span>
                                </label>
                            </div>

                            <div class="rp-toggle-row" style="flex-direction:column;align-items:stretch;gap:8px;">
                                <div style="display:flex;gap:14px;">
                                    <div class="rp-toggle-icon"><?php echo self::iconShield(); ?></div>
                                    <div class="rp-toggle-body">
                                        <strong>Production hostnames</strong>
                                        <div class="rp-desc">Comma-separated glob patterns. If <code>siteurl</code> matches any of these, <code>execute-php</code> is unconditionally refused.</div>
                                    </div>
                                </div>
                                <input type="text" name="production_hosts" class="rp-input rp-input-mono" placeholder="e.g. mysite.com, *.client-prod.com" value="<?php echo esc_attr($prodHosts); ?>" style="margin-left:50px;width:calc(100% - 50px);">
                            </div>

                        </div>
                        <div class="rp-card-foot">
                            <div style="font-size:12px;color:var(--rp-text-muted);">Changes apply immediately when saved.</div>
                            <button type="submit" class="rp-btn rp-btn-primary">Save settings</button>
                        </div>
                    </div>
                </form>

                <!-- Audit log -->
                <div class="rp-card">
                    <div class="rp-card-head">
                        <div>
                            <h3 style="display:flex;align-items:center;gap:8px;">Audit log <span style="font-size:11.5px;font-weight:500;color:var(--rp-text-muted);">last 50 entries</span></h3>
                            <div class="rp-sub">Full payloads under <code>wp-content/uploads/rolepod-wp-audit/</code></div>
                        </div>
                    </div>
                    <div class="rp-card-pad">
                        <?php self::renderAuditTimeline(Log::tail(50)); ?>
                    </div>
                </div>

            </div>

            <!-- Right column -->
            <aside class="rp-stack">
                <?php self::renderGuardianCard($guardianInstalled, $guardianPath, $recentFatals); ?>
                <?php self::renderPluginInfoCard(); ?>
            </aside>

        </div>

        <?php
        Shell::close();
    }

    private static function renderGuardianCard(bool $installed, string $path, array $recentFatals): void
    {
        ?>
        <div class="rp-card">
            <div class="rp-card-head" style="padding:14px 18px 12px;">
                <div>
                    <h3 style="font-size:13.5px;">Recovery guardian</h3>
                    <div class="rp-sub" style="font-size:12px;">mu-plugin &middot; loads before everything</div>
                </div>
                <?php if ($installed): ?>
                    <span class="rp-badge rp-badge-success"><span class="rp-bd"></span>Installed</span>
                <?php else: ?>
                    <span class="rp-badge rp-badge-danger"><span class="rp-bd"></span>Off</span>
                <?php endif; ?>
            </div>
            <div style="padding:12px 18px;">
                <p style="margin:0 0 12px;font-size:12px;color:var(--rp-text-muted);line-height:1.55;">
                    If the main plugin or any theme crashes with a fatal, guardian's REST endpoints under <code>/wplab-recovery/v1/*</code> stay reachable so the MCP can disable the offending file or restore a snapshot &mdash; without SSH or FTP.
                </p>
                <?php if ($installed): ?>
                    <div style="background:var(--rp-surface-sunken);border-radius:6px;padding:7px 9px;font-family:var(--rp-font-mono);font-size:11px;color:var(--rp-text-muted);word-break:break-all;margin-bottom:10px;">
                        <?php echo esc_html($path); ?>
                    </div>
                <?php endif; ?>
                <form method="post" style="display:flex;gap:6px;flex-wrap:wrap;">
                    <?php wp_nonce_field(self::NONCE_ACTION . '_guardian', 'rolepod_wp_guardian_nonce'); ?>
                    <?php if ($installed): ?>
                        <button type="submit" name="guardian_action" value="reinstall" class="rp-btn rp-btn-sm">Reinstall</button>
                        <button type="submit" name="guardian_action" value="remove" class="rp-btn rp-btn-sm rp-btn-ghost" data-rp-confirm="Remove the recovery guardian? Crash-recovery REST endpoints will be unreachable until reinstalled.">Remove</button>
                    <?php else: ?>
                        <button type="submit" name="guardian_action" value="install" class="rp-btn rp-btn-sm rp-btn-primary">Install</button>
                    <?php endif; ?>
                </form>

                <?php if (!empty($recentFatals)): ?>
                    <div style="margin-top:14px;border-top:1px solid var(--rp-border);padding-top:12px;">
                        <div style="font-size:11.5px;font-weight:600;color:var(--rp-warning-text);margin-bottom:8px;">Recent fatals (<?php echo (int) count($recentFatals); ?>)</div>
                        <div style="font-size:11.5px;line-height:1.5;color:var(--rp-text-muted);max-height:160px;overflow:auto;">
                            <?php foreach (array_reverse($recentFatals) as $fatal): ?>
                                <div style="margin-bottom:6px;">
                                    <div class="rp-mono" style="font-size:11px;color:var(--rp-text);"><?php echo esc_html((string) ($fatal['file'] ?? '')); ?>:<?php echo (int) ($fatal['line'] ?? 0); ?></div>
                                    <div style="word-break:break-word;"><?php echo esc_html((string) ($fatal['message'] ?? '')); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <form method="post" style="margin-top:8px;">
                            <?php wp_nonce_field(self::NONCE_ACTION . '_guardian', 'rolepod_wp_guardian_nonce'); ?>
                            <button type="submit" name="guardian_action" value="clear_fatals" class="rp-btn rp-btn-sm rp-btn-ghost">Clear log</button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private static function renderPluginInfoCard(): void
    {
        ?>
        <div class="rp-card">
            <div style="padding:16px 18px;">
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">
                    <div class="rp-logo" style="width:30px;height:30px;font-size:13px;border-radius:8px;">R</div>
                    <div>
                        <div style="font-weight:600;font-size:13px;">Rolepod for WordPress</div>
                        <div style="font-size:11.5px;color:var(--rp-text-muted);">v<?php echo esc_html(ROLEPOD_WP_VERSION); ?></div>
                    </div>
                </div>
                <div style="display:grid;gap:6px;font-size:12.5px;">
                    <div style="display:flex;justify-content:space-between;gap:8px;"><span class="rp-muted">Surface</span><span class="rp-mono">/wp-json/wplab/v1/</span></div>
                    <div style="display:flex;justify-content:space-between;gap:8px;"><span class="rp-muted">MCP</span><span class="rp-mono">rolepod-wplab</span></div>
                    <div style="display:flex;justify-content:space-between;gap:8px;"><span class="rp-muted">Ecosystem</span><a href="https://github.com/nuttaruj/rolepod" target="_blank" rel="noopener" style="color:var(--rp-accent);">Rolepod &rarr;</a></div>
                </div>
            </div>
        </div>
        <?php
    }

    private static function renderAuditTimeline(array $rows): void
    {
        if (empty($rows)) {
            echo '<p style="margin:8px 0;color:var(--rp-text-muted);"><em>No audit entries yet.</em></p>';
            return;
        }
        // Group by day (YYYY-MM-DD)
        $groups = [];
        foreach ($rows as $row) {
            $ts = (string) ($row['timestamp'] ?? '');
            $day = substr($ts, 0, 10);
            if (!isset($groups[$day])) $groups[$day] = [];
            $groups[$day][] = $row;
        }

        echo '<div class="rp-timeline">';
        foreach ($groups as $day => $items) {
            $count = count($items);
            echo '<div class="rp-timeline-day">';
            echo '  <div class="rp-timeline-day-label">' . esc_html(self::formatDay($day)) . '</div>';
            echo '  <div class="rp-timeline-day-line"></div>';
            echo '  <div class="rp-timeline-day-count">' . (int) $count . ' ' . ($count === 1 ? 'entry' : 'entries') . '</div>';
            echo '</div>';
            echo '<div class="rp-timeline-items">';
            foreach ($items as $row) {
                $ep = (string) ($row['endpoint'] ?? '');
                $isErr = ((string) ($row['result'] ?? '')) !== 'success';
                $tone = self::endpointTone($ep);
                $time = substr((string) ($row['timestamp'] ?? ''), 11, 8);
                $auditId = (string) ($row['audit_id'] ?? '');
                $shortId = '';
                if (preg_match('/_([a-f0-9]+)$/', $auditId, $m)) $shortId = $m[1];

                echo '<div class="rp-timeline-entry' . ($isErr ? ' is-error' : '') . '">';
                echo '  <div class="rp-timeline-icon t-' . esc_attr($tone) . '">' . self::endpointIconSvg($ep) . '</div>';
                echo '  <div class="rp-timeline-body">';
                echo '    <div class="rp-tl-row">';
                echo '      <span class="rp-tl-ep">' . esc_html($ep) . '</span>';
                echo '      <span class="rp-badge rp-badge-' . ($isErr ? 'danger' : 'success') . '"><span class="rp-bd"></span>' . esc_html((string) ($row['result'] ?? '')) . '</span>';
                echo '      <span class="rp-tl-user">by ' . esc_html((string) ($row['user'] ?? '')) . '</span>';
                echo '    </div>';
                $err = (string) ($row['error'] ?? '');
                if ($err !== '') {
                    echo '    <div class="rp-tl-err">' . esc_html($err) . '</div>';
                }
                echo '  </div>';
                echo '  <div class="rp-timeline-meta">';
                echo '    <div class="rp-tl-time">' . esc_html($time) . '</div>';
                echo '    <div class="rp-tl-id">' . esc_html($shortId) . '</div>';
                echo '  </div>';
                echo '</div>';
            }
            echo '</div>';
        }
        echo '</div>';
    }

    private static function formatDay(string $d): string
    {
        $today = gmdate('Y-m-d');
        $yesterday = gmdate('Y-m-d', time() - 86400);
        if ($d === $today) return 'Today';
        if ($d === $yesterday) return 'Yesterday';
        return $d;
    }

    private static function endpointTone(string $ep): string
    {
        if ($ep === 'execute-php') return 'warning';
        if (strpos($ep, 'theme/') === 0) return 'info';
        if (strpos($ep, 'fs-') === 0) return 'info';
        if (strpos($ep, 'admin/') === 0) return 'accent';
        return 'neutral';
    }

    private static function endpointIconSvg(string $ep): string
    {
        if ($ep === 'execute-php')       return self::iconCode();
        if ($ep === 'wp-cli')            return self::iconTerminal();
        if (strpos($ep, 'theme/') === 0) return self::iconTheme();
        if (strpos($ep, 'fs-') === 0)    return self::iconFolder();
        if (strpos($ep, 'admin/') === 0) return self::iconKey();
        return self::iconPlug();
    }

    private static function handleGuardianAction(): ?array
    {
        if (!current_user_can('manage_options')) {
            return null;
        }
        $action = isset($_POST['guardian_action'])
            ? sanitize_text_field((string) wp_unslash($_POST['guardian_action']))
            : '';

        switch ($action) {
            case 'install':
            case 'reinstall':
                $result = Guardian::install();
                if ($result['ok']) {
                    return ['type' => 'success', 'message' => 'Guardian installed at <code>' . esc_html((string) $result['path']) . '</code>.'];
                }
                return ['type' => 'error', 'message' => 'Guardian install failed: <code>' . esc_html((string) ($result['error'] ?? 'UNKNOWN')) . '</code>'];
            case 'remove':
                Guardian::remove();
                return ['type' => 'warning', 'message' => 'Guardian removed. Reinstall to restore crash-recovery endpoints.'];
            case 'clear_fatals':
                delete_transient('rolepod_wp_recovery_recent_fatals');
                return ['type' => 'success', 'message' => 'Fatal log cleared.'];
            case 'clear_safe_mode':
                update_option('rolepod_wp_safe_mode', false, false);
                return ['type' => 'success', 'message' => 'Safe mode cleared.'];
        }
        return null;
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
    }

    // Inline SVG icons.
    private static function iconPlug(): string
    {
        return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden="true"><path d="M9 8V4M15 8V4M7 8h10v4a5 5 0 0 1-10 0z M12 17v4"/></svg>';
    }
    private static function iconCode(): string
    {
        return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m8 8-4 4 4 4M16 8l4 4-4 4M14 4l-4 16"/></svg>';
    }
    private static function iconShield(): string
    {
        return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/></svg>';
    }
    private static function iconTerminal(): string
    {
        return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m4 8 4 4-4 4M11 16h7"/></svg>';
    }
    private static function iconTheme(): string
    {
        return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" aria-hidden="true"><path d="M9 21l-3-3 9-9 3 3zM14 6l3-3 3 3-3 3"/></svg>';
    }
    private static function iconFolder(): string
    {
        return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" aria-hidden="true"><path d="M3 6a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>';
    }
    private static function iconKey(): string
    {
        return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true"><circle cx="7.5" cy="15.5" r="4"/><path d="m10.5 13 9-9M16 7l3 3M14 9l3 3"/></svg>';
    }
}
