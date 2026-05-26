<?php
declare(strict_types=1);

namespace Rolepod\Wp\Admin;

use Rolepod\Wp\Audit\ChangeRecorder;
use Rolepod\Wp\Audit\Toggler;
use Rolepod\Wp\Audit\HookWrapper;

/**
 * Tools → Rolepod WP → Change Ledger
 *
 * Surfaces every AI-issued change recorded by the MCP. Tabs by category,
 * bulk-action checkboxes for disable/re-enable, panic button to disable all
 * changes in the last N minutes.
 *
 * Bypasses the WP_List_Table class on purpose — the surface is simple enough
 * that a hand-rolled table is shorter + easier to maintain than the WP class
 * with its many overridable methods.
 */
final class ChangeLedgerPage
{
    private const SLUG = 'rolepod-wp-changes';
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

    public static function register(): void
    {
        add_management_page(
            'Rolepod WP — Change Ledger',
            'Rolepod WP Changes',
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

        ?>
        <div class="wrap">
            <h1>Rolepod WP — Change Ledger</h1>
            <p>Every write the MCP issued through this companion. Disable a row to revert that change; re-enable to re-apply. Use the panic button below to disable every change in a time window at once.</p>

            <?php if ($notice !== null): ?>
                <div class="notice notice-<?php echo esc_attr($notice['type']); ?> is-dismissible">
                    <p><?php echo esc_html($notice['message']); ?></p>
                </div>
            <?php endif; ?>

            <h2 class="nav-tab-wrapper">
                <?php foreach (self::CATEGORIES as $key => $label): ?>
                    <?php $count = self::countForCategory($key); ?>
                    <a href="<?php echo esc_url(add_query_arg(['page' => self::SLUG, 'cat' => $key], admin_url('tools.php'))); ?>"
                       class="nav-tab <?php echo $activeTab === $key ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html($label); ?> (<?php echo (int) $count; ?>)
                    </a>
                <?php endforeach; ?>
            </h2>

            <form method="post" style="margin-top:16px;">
                <?php wp_nonce_field(self::NONCE_ACTION, 'rolepod_wp_changes_nonce'); ?>
                <input type="hidden" name="cat" value="<?php echo esc_attr($activeTab); ?>">

                <div class="tablenav top">
                    <div class="alignleft actions bulkactions">
                        <select name="bulk_action">
                            <option value="">Bulk actions</option>
                            <option value="disable">Disable selected</option>
                            <option value="enable">Re-enable selected</option>
                        </select>
                        <button type="submit" class="button">Apply</button>
                    </div>
                    <div class="alignright">
                        <label>Panic — disable everything in the last
                            <select name="panic_minutes">
                                <option value="10">10 min</option>
                                <option value="60">1 hour</option>
                                <option value="1440">24 hours</option>
                            </select>
                            <button type="submit" name="panic_submit" value="1" class="button button-secondary" onclick="return confirm('Disable every change in this window? This reverts AI-issued writes to before the window started.');">🚨 Panic disable</button>
                        </label>
                    </div>
                </div>

                <table class="widefat striped">
                    <thead>
                        <tr>
                            <td class="manage-column column-cb check-column"><input type="checkbox" id="ledger-cb-all" onclick="document.querySelectorAll('input[name=\\'ids[]\\']').forEach(function(c){c.checked=this.checked;}.bind(this));"></td>
                            <th>ID</th>
                            <th>When (UTC)</th>
                            <th>Category</th>
                            <th>Target</th>
                            <th>Source tool</th>
                            <th>Status</th>
                            <th>Reversible</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="9"><em>No changes recorded yet.</em></td></tr>
                        <?php else: foreach ($rows as $row): ?>
                            <tr>
                                <td class="check-column"><input type="checkbox" name="ids[]" value="<?php echo (int) $row['id']; ?>"></td>
                                <td>#<?php echo (int) $row['id']; ?></td>
                                <td><?php echo esc_html((string) $row['created_at']); ?></td>
                                <td>
                                    <code><?php echo esc_html((string) $row['category']); ?></code><br>
                                    <small><?php echo esc_html((string) $row['subcategory']); ?></small>
                                </td>
                                <td><?php echo esc_html((string) $row['target_descriptor']); ?></td>
                                <td><small><code><?php echo esc_html((string) ($row['source_tool'] ?? '')); ?></code></small></td>
                                <td>
                                    <?php if ((int) $row['applied'] === 1): ?>
                                        <span style="color:#1a7f37;font-weight:600;">● Applied</span>
                                    <?php else: ?>
                                        <span style="color:#9a3412;font-weight:600;">○ Disabled</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo (int) $row['reversible'] === 1 ? '✓' : '⚠️'; ?></td>
                                <td>
                                    <?php
                                    $isApplied = (int) $row['applied'] === 1;
                                    $toggleAction = $isApplied ? 'disable_one' : 'enable_one';
                                    $toggleLabel = $isApplied ? 'Disable' : 'Re-enable';
                                    ?>
                                    <button type="submit" name="<?php echo esc_attr($toggleAction); ?>" value="<?php echo (int) $row['id']; ?>" class="button button-small"><?php echo esc_html($toggleLabel); ?></button>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </form>
        </div>
        <?php
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

    /**
     * Handle POST: bulk action OR single toggle OR panic. Returns notice
     * array or null.
     */
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
}
