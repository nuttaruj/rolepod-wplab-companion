<?php
declare(strict_types=1);

namespace Rolepod\Wp\Audit;

/**
 * Recorder + toggler for the change ledger.
 *
 * The MCP side calls `record()` with before+after state every time it issues
 * a write through a tool that opts into the ledger (post_update, option_set,
 * file_write, scaffold, adapter writes). The admin UI + the MCP query/toggle
 * tools read + flip rows through this class.
 *
 * Per-category dispatchers live in `Toggler.php`; this class is just the
 * row CRUD.
 */
final class ChangeRecorder
{
    public static function record(array $row): string
    {
        global $wpdb;
        $table = ChangeLedger::tableName();

        $auditId = $row['audit_id'] ?? ('rolepod_wp_change_' . bin2hex(random_bytes(8)));
        $now = gmdate('Y-m-d H:i:s');

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->insert(
            $table,
            [
                'audit_id' => $auditId,
                'category' => (string) ($row['category'] ?? 'unknown'),
                'subcategory' => (string) ($row['subcategory'] ?? ''),
                'target_descriptor' => (string) ($row['target_descriptor'] ?? ''),
                'before_state' => isset($row['before_state']) ? wp_json_encode($row['before_state']) : null,
                'after_state' => isset($row['after_state']) ? wp_json_encode($row['after_state']) : null,
                'applied' => 1,
                'reversible' => !empty($row['reversible']) ? 1 : (isset($row['reversible']) ? 0 : 1),
                'source_tool' => $row['source_tool'] ?? null,
                'source_session' => $row['source_session'] ?? null,
                'created_at' => $now,
                'notes' => $row['notes'] ?? null,
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s']
        );

        return $auditId;
    }

    /**
     * Filter rows. Returns an array of row arrays (assoc).
     */
    public static function query(array $filters = []): array
    {
        global $wpdb;
        $table = ChangeLedger::tableName();

        $where = ['1=1'];
        $params = [];

        if (!empty($filters['category'])) {
            $where[] = 'category = %s';
            $params[] = $filters['category'];
        }
        if (isset($filters['applied'])) {
            $where[] = 'applied = %d';
            $params[] = $filters['applied'] ? 1 : 0;
        }
        if (!empty($filters['since_minutes'])) {
            $where[] = 'created_at >= %s';
            $params[] = gmdate('Y-m-d H:i:s', time() - (int) $filters['since_minutes'] * 60);
        }
        if (!empty($filters['source_session'])) {
            $where[] = 'source_session = %s';
            $params[] = $filters['source_session'];
        }
        if (!empty($filters['ids']) && is_array($filters['ids'])) {
            $placeholders = implode(',', array_fill(0, count($filters['ids']), '%d'));
            $where[] = "id IN ({$placeholders})";
            $params = array_merge($params, array_map('intval', $filters['ids']));
        }

        $limit = isset($filters['limit']) ? max(1, min(500, (int) $filters['limit'])) : 100;
        $sql = "SELECT * FROM {$table} WHERE " . implode(' AND ', $where)
            . " ORDER BY created_at DESC LIMIT {$limit}";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results($params ? $wpdb->prepare($sql, $params) : $sql, ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }

        foreach ($rows as &$r) {
            $r['before_state'] = isset($r['before_state']) ? json_decode((string) $r['before_state'], true) : null;
            $r['after_state'] = isset($r['after_state']) ? json_decode((string) $r['after_state'], true) : null;
            $r['applied'] = (int) $r['applied'];
            $r['reversible'] = (int) $r['reversible'];
        }
        unset($r);

        return $rows;
    }

    public static function getById(int $id): ?array
    {
        global $wpdb;
        $table = ChangeLedger::tableName();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id), ARRAY_A);
        if (!is_array($row)) {
            return null;
        }
        $row['before_state'] = isset($row['before_state']) ? json_decode((string) $row['before_state'], true) : null;
        $row['after_state'] = isset($row['after_state']) ? json_decode((string) $row['after_state'], true) : null;
        $row['applied'] = (int) $row['applied'];
        $row['reversible'] = (int) $row['reversible'];
        return $row;
    }

    public static function setApplied(int $id, bool $applied): bool
    {
        global $wpdb;
        $table = ChangeLedger::tableName();
        $now = gmdate('Y-m-d H:i:s');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $result = $wpdb->update(
            $table,
            ['applied' => $applied ? 1 : 0, 'toggled_at' => $now],
            ['id' => $id],
            ['%d', '%s'],
            ['%d']
        );
        return $result !== false;
    }

    /**
     * Bulk disable / enable by id list.
     * Returns map of id → success bool.
     */
    public static function setAppliedBulk(array $ids, bool $applied): array
    {
        $result = [];
        foreach ($ids as $id) {
            $intId = (int) $id;
            $result[$intId] = self::setApplied($intId, $applied);
        }
        return $result;
    }

    /**
     * Panic: disable every change in the last N minutes that is currently applied.
     * Returns the list of disabled ids.
     */
    public static function panic(int $sinceMinutes): array
    {
        $rows = self::query(['since_minutes' => $sinceMinutes, 'applied' => true, 'limit' => 500]);
        $ids = array_map(static fn ($r) => (int) $r['id'], $rows);
        if (empty($ids)) {
            return [];
        }
        self::setAppliedBulk($ids, false);
        return $ids;
    }
}
