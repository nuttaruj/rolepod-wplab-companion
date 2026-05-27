<?php
declare(strict_types=1);

namespace Rolepod\Wp\Admin;

use Rolepod\Wp\Config;

/**
 * Shared page shell for all Rolepod WP admin pages — header, sub-nav,
 * and a consistent .rp-shell wrapper that scopes all design tokens.
 *
 * All output uses static markup; no buffering, no template engine. Each
 * page renders directly into the WP admin body — same speed as the
 * legacy plain-html pages, with a much tighter visual layer.
 */
final class Shell
{
    /**
     * Render header + open the shell wrapper.
     *
     * @param string $activeSlug One of Menu::SLUG_* constants.
     */
    public static function open(string $activeSlug): void
    {
        $config = Config::all();
        $endpointsEnabled = (bool) ($config['endpoints_enabled'] ?? false);
        $statusLabel = $endpointsEnabled ? 'Endpoints ON' : 'Endpoints OFF';
        $statusClass = $endpointsEnabled ? '' : 'is-off';

        $tabs = [
            ['slug' => Menu::SLUG_SETUP,    'label' => 'Setup',          'icon' => self::iconSparkle()],
            ['slug' => Menu::SLUG_CHANGES,  'label' => 'Change Ledger',  'icon' => self::iconList()],
            ['slug' => Menu::SLUG_SETTINGS, 'label' => 'Settings',       'icon' => self::iconCog()],
        ];

        echo '<div class="wrap rp-shell">';
        echo '<header class="rp-pageheader">';
        echo '  <div class="rp-pageheader-l">';
        echo '    <div class="rp-logo" aria-hidden="true">R</div>';
        echo '    <div>';
        echo '      <h1>Rolepod for WordPress</h1>';
        echo '      <div class="rp-version">';
        echo '        <span class="rp-dot ' . esc_attr($statusClass) . '" aria-hidden="true"></span>';
        echo '        v' . esc_html(ROLEPOD_WP_VERSION) . ' &middot; ' . esc_html($statusLabel);
        echo '      </div>';
        echo '    </div>';
        echo '  </div>';
        echo '  <a class="rp-btn rp-btn-ghost" href="https://github.com/nuttaruj/rolepod-wplab" target="_blank" rel="noopener">Docs &rarr;</a>';
        echo '</header>';

        echo '<nav class="rp-subnav" aria-label="Rolepod WP sections">';
        foreach ($tabs as $tab) {
            $isActive = $tab['slug'] === $activeSlug;
            $cls = $isActive ? 'is-active' : '';
            echo '<a class="' . esc_attr($cls) . '" href="' . esc_url(Menu::url($tab['slug'])) . '">';
            echo $tab['icon'];
            echo esc_html($tab['label']);
            echo '</a>';
        }
        echo '</nav>';
    }

    public static function close(): void
    {
        echo '</div>';
    }

    /** Inline SVG icons used in sub-nav. Tiny, no font fetch. */
    private static function iconSparkle(): string
    {
        return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true"><path d="M12 3v6M12 15v6M3 12h6M15 12h6M6 6l3 3M15 15l3 3M6 18l3-3M15 9l3-3"/></svg>';
    }

    private static function iconList(): string
    {
        return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h10"/></svg>';
    }

    private static function iconCog(): string
    {
        return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8L4.2 7a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg>';
    }
}
