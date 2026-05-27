<?php
declare(strict_types=1);

namespace Rolepod\Wp;

/**
 * Guardian lifecycle controller.
 *
 * The mu-plugin guardian (`guardian/rolepod-wp-guardian.php`) is a
 * SELF-CONTAINED file that survives main-plugin parse errors because WP loads
 * mu-plugins before regular plugins. This class handles installing it into
 * `wp-content/mu-plugins/` on activation and removing it on deactivation /
 * uninstall.
 *
 * Tight coupling (Option A): deactivate main → remove guardian. Predictable
 * "deactivate = off completely" UX. Re-activate copies it back.
 */
final class Guardian
{
    public const FILENAME = 'rolepod-wp-guardian.php';

    public static function sourcePath(): string
    {
        return ROLEPOD_WP_DIR . 'guardian/' . self::FILENAME;
    }

    public static function destinationPath(): string
    {
        return WPMU_PLUGIN_DIR . '/' . self::FILENAME;
    }

    public static function isInstalled(): bool
    {
        return is_file(self::destinationPath());
    }

    /**
     * Copy guardian into the mu-plugins dir. Creates the dir if missing.
     * Returns array{ok: bool, path: string, error?: string}.
     */
    public static function install(): array
    {
        $src = self::sourcePath();
        $dest = self::destinationPath();

        if (!is_file($src)) {
            return ['ok' => false, 'path' => $dest, 'error' => 'GUARDIAN_SOURCE_MISSING'];
        }

        if (!is_dir(WPMU_PLUGIN_DIR)) {
            if (!wp_mkdir_p(WPMU_PLUGIN_DIR)) {
                return ['ok' => false, 'path' => $dest, 'error' => 'MU_PLUGIN_DIR_UNWRITABLE'];
            }
        }

        if (!@copy($src, $dest)) {
            return ['ok' => false, 'path' => $dest, 'error' => 'COPY_FAILED'];
        }
        @chmod($dest, 0644);

        return ['ok' => true, 'path' => $dest];
    }

    /**
     * Remove guardian from mu-plugins. Silently succeeds if not installed.
     */
    public static function remove(): bool
    {
        $dest = self::destinationPath();
        if (!is_file($dest)) {
            return true;
        }
        return @unlink($dest);
    }
}
