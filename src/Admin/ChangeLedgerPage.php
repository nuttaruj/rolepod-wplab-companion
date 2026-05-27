<?php
declare(strict_types=1);

namespace Rolepod\Wp\Admin;

use Rolepod\Wp\Audit\ChangeRecorder;
use Rolepod\Wp\Audit\Toggler;
use Rolepod\Wp\Audit\HookWrapper;

/**
 * Change Ledger page — top-level submenu "Change Ledger".
 *
 * Surfaces every AI-issued change recorded by the MCP. Tabs by category,
 * bulk-action checkboxes for disable/re-enable, panic button to disable
 * all changes in the last N minutes, stat tiles at the top.
 */
final class ChangeLedgerPage
{
    private const NONCE_ACTION = 'rolepod_wp_changes_action';

    private const CATEGORIES = [
        'all' => 'All',
        'hook' => 'Hooks',
        'post' => 'Content',
        'option' => 'Options',
        'layout' => 'Layouts',
        'file' => 'Files',
        'plugin' => 'Plugins',
        'theme' => 'Themes',
        'execute_php' => 'execute-php',
    ];

    public static function register(): void { /* registered via Menu::register() */ }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions.');
        }

        $notice = self::handlePost();

        $activeTab = isset($_GET['cat']) ? sanitize_key((string) wp_unslash($_GET['cat'])) : 'all';
        if (!array_key_exists($activeTab, self::CATEGORIES)) {
            $activeTab = 'all';
        }

        $filters = ['limit' => 200];
        if ($activeTab !== 'all') {
            $filters['category'] = $activeTab;
        }
        $rows = ChangeRecorder::query($filters);

        $totals = self::computeTotals();

        Shell::open(Menu::SLUG_CHANGES, 'Change Ledger', 'Every AI-issued write — reversible by row.');

        if ($notice !== null) {
            echo '<div class="notice notice-' . esc_attr($notice['type']) . ' is-dismissible"><p>' . esc_html($notice['message']) . '</p></div>';
        }

        ?>

        <!-- Intro + panic -->
        <div style="display:flex;justify-content:space-between;align-items:center;gap:24px;margin-bottom:14px;flex-wrap:wrap;">
            <div style="font-size:13px;color:var(--rp-text-muted);max-width:680px;line-height:1.6;">
                Every write the MCP issues passes through this ledger. Disable a row to revert that change; re-enable to re-apply. Use <strong>panic disable</strong> to revert everything in a time window at once.
            </div>
            <form method="post" style="margin:0;">
                <?php wp_nonce_field(self::NONCE_ACTION, 'rolepod_wp_changes_nonce'); ?>
                <input type="hidden" name="cat" value="<?php echo esc_attr($activeTab); ?>">
                <div class="rp-panic">
                    <div class="rp-panic-icon" aria-hidden="true"><?php echo self::iconBomb(); ?></div>
                    <div class="rp-panic-text">
                        <strong>Panic disable</strong>
                        <small>Revert everything in the last…</small>
                    </div>
                    <select name="panic_minutes">
                        <option value="10">10 min</option>
                        <option value="60">1 hour</option>
                        <option value="1440">24 hours</option>
                    </select>
                    <button type="submit" name="panic_submit" value="1" class="rp-btn rp-btn-sm rp-btn-danger" data-rp-confirm="Disable every change in this window? This reverts AI-issued writes from before the window started.">Disable</button>
                </div>
            </form>
        </div>

        <!-- Stat tiles -->
        <div class="rp-stat-grid">
            <div class="rp-stat"><div class="rp-stat-icon t-accent"><?php echo self::iconActivity(); ?></div><div><div class="rp-stat-label">Total changes</div><div class="rp-stat-value"><?php echo (int) $totals['total']; ?></div></div></div>
            <div class="rp-stat"><div class="rp-stat-icon t-success"><?php echo self::iconCheck(); ?></div><div><div class="rp-stat-label">Active</div><div class="rp-stat-value"><?php echo (int) $totals['active']; ?></div></div></div>
            <div class="rp-stat"><div class="rp-stat-icon t-neutral"><?php echo self::iconMinus(); ?></div><div><div class="rp-stat-label">Disabled</div><div class="rp-stat-value"><?php echo (int) $totals['disabled']; ?></div></div></div>
            <div class="rp-stat"><div class="rp-stat-icon t-warning"><?php echo self::iconAlert(); ?></div><div><div class="rp-stat-label">Non-reversible</div><div class="rp-stat-value"><?php echo (int) $totals['non_reversible']; ?></div></div></div>
        </div>

        <!-- Category tabs -->
        <div class="rp-subnav" style="margin-bottom:14px;">
            <?php foreach (self::CATEGORIES as $key => $label): ?>
                <?php $count = self::countForCategory($key); ?>
                <a class="<?php echo $activeTab === $key ? 'is-active' : ''; ?>" href="<?php echo esc_url(add_query_arg(['page' => Menu::SLUG_CHANGES, 'cat' => $key], admin_url('admin.php'))); ?>">
                    <?php echo esc_html($label); ?>
                    <?php if ($count > 0): ?><span class="rp-count"><?php echo (int) $count; ?></span><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Toolbar + table -->
        <form method="post" style="margin:0;">
            <?php wp_nonce_field(self::NONCE_ACTION, 'rolepod_wp_changes_nonce'); ?>
            <input type="hidden" name="cat" value="<?php echo esc_attr($activeTab); ?>">

            <div class="rp-card" style="overflow:hidden;margin-bottom:0;">
                <div class="rp-toolbar">
                    <div style="display:flex;align-items:center;gap:10px;flex:1;flex-wrap:wrap;">
                        <input type="search" data-rp-search=".rp-ledger-row" class="rp-input" placeholder="Filter by target, source, or description&hellip;" style="max-width:320px;font-size:12.5px;">
                    </div>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <select name="bulk_action" class="rp-input" style="width:auto;font-size:12.5px;">
                            <option value="">Bulk actions</option>
                            <option value="disable">Disable selected</option>
                            <option value="enable">Re-enable selected</option>
                        </select>
                        <button type="submit" class="rp-btn rp-btn-sm">Apply</button>
                    </div>
                </div>

                <?php if (empty($rows)): ?>
                    <div style="padding:36px 18px;text-align:center;color:var(--rp-text-muted);font-size:13px;">
                        No changes recorded yet.
                    </div>
                <?php else: ?>
                    <table class="rp-ledger-table">
                        <thead>
                            <tr>
                                <th class="col-check"><input type="checkbox" data-rp-toggle-all="ledger"></th>
                                <th class="col-id">ID</th>
                                <th class="col-when">When (UTC)</th>
                                <th class="col-cat">Category</th>
                                <th>Target</th>
                                <th class="col-source">Source</th>
                                <th class="col-status">Status</th>
                                <th class="col-rev">Rev</th>
                                <th class="col-actions"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row): ?>
                                <?php
                                $isApplied = (int) $row['applied'] === 1;
                                $isReversible = (int) $row['reversible'] === 1;
                                $target = (string) ($row['target_descriptor'] ?? '');
                                $targetKey = (string) ($row['subcategory'] ?? '');
                                $source = (string) ($row['source_tool'] ?? '');
                                $hay = strtolower($target . ' ' . $targetKey . ' ' . $source);
                                ?>
                                <tr class="rp-ledger-row<?php echo $isApplied ? '' : ' is-disabled'; ?>" data-rp-haystack="<?php echo esc_attr($hay); ?>">
                                    <td class="col-check"><input type="checkbox" name="ids[]" value="<?php echo (int) $row['id']; ?>" data-rp-group="ledger"></td>
                                    <td class="col-id">#<?php echo (int) $row['id']; ?></td>
                                    <td class="col-when rp-mono" style="font-size:11.5px;color:var(--rp-text-muted);">
                                        <?php echo esc_html((string) $row['created_at']); ?>
                                    </td>
                                    <td class="col-cat">
                                        <span class="rp-badge rp-badge-<?php echo esc_attr(self::categoryTone((string) $row['category'])); ?>"><?php echo esc_html((string) $row['category']); ?></span>
                                    </td>
                                    <td class="col-target">
                                        <div><?php echo esc_html($target); ?></div>
                                        <?php if ($targetKey !== ''): ?><small><?php echo esc_html($targetKey); ?></small><?php endif; ?>
                                    </td>
                                    <td class="col-source"><span class="rp-chip"><?php echo esc_html($source); ?></span></td>
                                    <td class="col-status">
                                        <?php if ($isApplied): ?>
                                            <span class="rp-badge rp-badge-success"><span class="rp-bd"></span>Active</span>
                                        <?php else: ?>
                                            <span class="rp-badge rp-badge-neutral"><span class="rp-bd"></span>Disabled</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="col-rev"><?php echo $isReversible ? '<span style="color:var(--rp-success);">&#10003;</span>' : '<span style="color:var(--rp-warning-text);" title="Not reversible">!</span>'; ?></td>
                                    <td class="col-actions">
                                        <?php if ($isReversible): ?>
                                            <?php $toggleAction = $isApplied ? 'disable_one' : 'enable_one'; ?>
                                            <?php $toggleLabel = $isApplied ? 'Disable' : 'Re-enable'; ?>
                                            <button type="submit" name="<?php echo esc_attr($toggleAction); ?>" value="<?php echo (int) $row['id']; ?>" class="rp-btn rp-btn-sm"><?php echo esc_html($toggleLabel); ?></button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </form>

        <?php
        Shell::footer();
        Shell::close();
    }

    private static function computeTotals(): array
    {
        global $wpdb;
        $table = \Rolepod\Wp\Audit\ChangeLedger::tableName();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $active = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE applied = 1");
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $disabled = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE applied = 0");
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $nonRev = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE reversible = 0");
        return [
            'total' => $total,
            'active' => $active,
            'disabled' => $disabled,
            'non_reversible' => $nonRev,
        ];
    }

    private static function countForCategory(string $category): int
    {
        global $wpdb;
        $table = \Rolepod\Wp\Audit\ChangeLedger::tableName();
        if ($category === 'all') {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE category = %s", $category));
    }

    private static function categoryTone(string $cat): string
    {
        $map = [
            'option' => 'accent', 'hook' => 'info', 'post' => 'success',
            'layout' => 'warning', 'file' => 'neutral', 'plugin' => 'accent',
            'theme' => 'info', 'execute_php' => 'danger',
        ];
        return $map[$cat] ?? 'neutral';
    }

    private static function handlePost(): ?array
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return null;
        }
        if (
            !isset($_POST['rolepod_wp_changes_nonce']) ||
            !wp_verify_nonce(
                sanitize_text_field((string) wp_unslash($_POST['rolepod_wp_changes_nonce'])),
                self::NONCE_ACTION
            )
        ) {
            return ['type' => 'error', 'message' => 'Invalid nonce.'];
        }

        if (!empty($_POST['panic_submit'])) {
            $minutes = max(1, min(1440, (int) ($_POST['panic_minutes'] ?? 10)));
            $ids = ChangeRecorder::panic($minutes);
            foreach ($ids as $id) {
                $row = ChangeRecorder::getById((int) $id);
                if ($row !== null) Toggler::apply($row, false);
            }
            HookWrapper::flushCache();
            return ['type' => 'warning', 'message' => "Panic: disabled " . count($ids) . " changes from the last {$minutes} minutes."];
        }

        if (isset($_POST['disable_one'])) {
            return self::singleToggle((int) $_POST['disable_one'], false);
        }
        if (isset($_POST['enable_one'])) {
            return self::singleToggle((int) $_POST['enable_one'], true);
        }

        $bulk = isset($_POST['bulk_action']) ? sanitize_key((string) wp_unslash($_POST['bulk_action'])) : '';
        $ids = isset($_POST['ids']) ? array_map('intval', (array) $_POST['ids']) : [];

        if ($bulk === '' || empty($ids)) {
            return null;
        }
        if (!in_array($bulk, ['disable', 'enable'], true)) {
            return null;
        }

        $newApplied = $bulk === 'enable';
        $okCount = 0;
        foreach ($ids as $id) {
            $row = ChangeRecorder::getById($id);
            if ($row === null) continue;
            ChangeRecorder::setApplied($id, $newApplied);
            $row = ChangeRecorder::getById($id);
            if ($row !== null) {
                $result = Toggler::apply($row, $newApplied);
                if ($result['ok']) $okCount++;
            }
        }
        HookWrapper::flushCache();
        return ['type' => 'success', 'message' => "Bulk {$bulk}: {$okCount}/" . count($ids) . " succeeded."];
    }

    private static function singleToggle(int $id, bool $applied): ?array
    {
        $row = ChangeRecorder::getById($id);
        if ($row === null) {
            return ['type' => 'error', 'message' => "Change #{$id} not found."];
        }
        ChangeRecorder::setApplied($id, $applied);
        $row = ChangeRecorder::getById($id);
        if ($row === null) return null;
        $result = Toggler::apply($row, $applied);
        HookWrapper::flushCache();
        $detail = $result['detail'] ?? '';
        $verb = $applied ? 're-enabled' : 'disabled';
        return [
            'type' => $result['ok'] ? 'success' : 'warning',
            'message' => "Change #{$id} {$verb}. " . ($detail !== '' ? "({$detail})" : ''),
        ];
    }

    // Inline SVG icons.
    private static function iconActivity(): string
    {
        return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12h4l3-9 4 18 3-9h4"/></svg>';
    }
    private static function iconCheck(): string
    {
        return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 12l5 5L20 6"/></svg>';
    }
    private static function iconMinus(): string
    {
        return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M5 12h14"/></svg>';
    }
    private static function iconAlert(): string
    {
        return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden="true"><path d="M12 3 2 20h20z"/><path d="M12 10v4"/><circle cx="12" cy="17" r=".6" fill="currentColor"/></svg>';
    }
    private static function iconBomb(): string
    {
        return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="14" r="7"/><path d="m17 8 2-2m-2 2 1.5 1.5M15 5l3-3"/></svg>';
    }
}
