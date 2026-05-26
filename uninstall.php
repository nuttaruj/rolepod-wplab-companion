<?php
/**
 * uninstall.php — executed when the user deletes the plugin via WP admin.
 *
 * Cleans up options + audit log dir. File audit logs preserved by default
 * (user owns the audit trail); only removed if they explicitly opt in via
 * the deletion confirmation. For v0.1 we delete the directory contents to
 * keep the WP install clean.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Remove options
delete_option('rolepod_wp_version');
delete_option('rolepod_wp_config');
delete_option('rolepod_wp_audit_log');

// Remove audit log directory
$uploadDir = wp_upload_dir();
if (is_array($uploadDir) && !empty($uploadDir['basedir'])) {
    $auditDir = trailingslashit($uploadDir['basedir']) . 'rolepod-wp-audit';
    if (is_dir($auditDir)) {
        $files = glob($auditDir . '/*.log');
        if (is_array($files)) {
            foreach ($files as $f) {
                @unlink($f);
            }
        }
        @rmdir($auditDir);
    }
}
