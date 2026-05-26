<?php
declare(strict_types=1);

namespace Rolepod\Wp\Audit;

/**
 * Per-change ledger of AI-issued writes through the MCP.
 *
 * Each row records:
 *   - what was changed (category + subcategory + target_descriptor)
 *   - before-state + after-state (JSON-serialized) so revert is mechanical
 *   - applied flag (1 = currently in effect, 0 = disabled)
 *   - reversible flag (0 = side effects cannot be undone, e.g. cache flush)
 *   - source tool + session for correlation
 *
 * The schema lives in a custom table so the audit-log option (capped at 1000
 * rows) and the ledger (which can grow large and is the rollback surface)
 * are not competing for the same wp_options budget. Indexed by category +
 * applied + created_at for the "show recent disabled hooks in this category"
 * query patterns the admin UI runs.
 */
final class ChangeLedger
{
    public const TABLE_VERSION = '1';
    public const TABLE_VERSION_OPTION = 'rolepod_wp_changes_schema_version';

    public static function tableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'rolepod_wp_changes';
    }

    /**
     * Create or upgrade the ledger table. Idempotent — safe to call on every
     * plugin activate.
     */
    public static function install(): void
    {
        global $wpdb;
        $table = self::tableName();
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            audit_id VARCHAR(64) NOT NULL,
            category VARCHAR(32) NOT NULL,
            subcategory VARCHAR(128) NOT NULL,
            target_descriptor VARCHAR(255) NOT NULL,
            before_state LONGTEXT NULL,
            after_state LONGTEXT NULL,
            applied TINYINT(1) NOT NULL DEFAULT 1,
            reversible TINYINT(1) NOT NULL DEFAULT 1,
            source_tool VARCHAR(64) NULL,
            source_session VARCHAR(64) NULL,
            created_at DATETIME NOT NULL,
            toggled_at DATETIME NULL,
            notes TEXT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY audit_id (audit_id),
            KEY category_applied_created (category, applied, created_at),
            KEY session_created (source_session, created_at),
            KEY applied_created (applied, created_at)
        ) {$charset};";

        dbDelta($sql);

        update_option(self::TABLE_VERSION_OPTION, self::TABLE_VERSION, false);
    }

    /**
     * Drop the table on uninstall. Called from uninstall.php only.
     */
    public static function uninstall(): void
    {
        global $wpdb;
        $table = self::tableName();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query("DROP TABLE IF EXISTS {$table}");
        delete_option(self::TABLE_VERSION_OPTION);
    }
}
