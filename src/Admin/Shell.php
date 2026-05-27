<?php
declare(strict_types=1);

namespace Rolepod\Wp\Admin;

use Rolepod\Wp\Config;

/**
 * Shared page shell for all Rolepod WP admin pages.
 *
 * Layout (matches brief/Demo mockup):
 *
 *   ┌──────────────────────────────────────────────────────────────┐
 *   │ [R logo] Rolepod for WordPress — <page>  │  [Setup][Ch][Set] │
 *   │          v2.8.x · <one-line subtitle>    │                   │
 *   └──────────────────────────────────────────────────────────────┘
 *   <content>
 *   <footer hint>
 */
final class Shell
{
    /**
     * Render header + open the wrapper. Caller must close with self::close().
     *
     * @param string      $activeSlug One of Menu::SLUG_* constants.
     * @param string      $pageLabel  Short label appended after the em-dash in the title.
     * @param string|null $subtitle   Optional single-line description shown under the version.
     */
    public static function open(string $activeSlug, string $pageLabel, ?string $subtitle = null): void
    {
        $config = Config::all();
        $endpointsEnabled = (bool) ($config['endpoints_enabled'] ?? false);
        $statusLabel = $endpointsEnabled ? 'Endpoints ON' : 'Endpoints OFF';
        $statusClass = $endpointsEnabled ? '' : 'is-off';

        $tabs = [
            ['slug' => Menu::SLUG_SETUP,    'label' => 'Setup',    'icon' => self::iconSparkle()],
            ['slug' => Menu::SLUG_CHANGES,  'label' => 'Changes',  'icon' => self::iconList()],
            ['slug' => Menu::SLUG_SETTINGS, 'label' => 'Settings', 'icon' => self::iconCog()],
        ];

        echo '<div class="wrap rp-shell">';
        echo '<header class="rp-pageheader">';
        echo '  <div class="rp-pageheader-l">';
        echo '    <div class="rp-logo" aria-hidden="true">R</div>';
        echo '    <div class="rp-pageheader-titles">';
        echo '      <h1>Rolepod for WordPress <span class="rp-pagetitle-sep">&mdash;</span> <span class="rp-pagetitle-page">' . esc_html($pageLabel) . '</span></h1>';
        echo '      <div class="rp-version">';
        echo '        <span class="rp-dot ' . esc_attr($statusClass) . '" aria-hidden="true"></span>';
        echo '        v' . esc_html(ROLEPOD_WP_VERSION) . ' &middot; ' . esc_html($statusLabel);
        if ($subtitle !== null && $subtitle !== '') {
            echo '        &nbsp;&middot;&nbsp;<span class="rp-subtitle">' . esc_html($subtitle) . '</span>';
        }
        echo '      </div>';
        echo '    </div>';
        echo '  </div>';

        echo '  <nav class="rp-subnav" aria-label="Rolepod WP sections">';
        foreach ($tabs as $tab) {
            $isActive = $tab['slug'] === $activeSlug;
            $cls = $isActive ? 'is-active' : '';
            echo '<a class="' . esc_attr($cls) . '" href="' . esc_url(Menu::url($tab['slug'])) . '">';
            echo $tab['icon'];
            echo esc_html($tab['label']);
            echo '</a>';
        }
        echo '  </nav>';

        echo '</header>';
    }

    /**
     * Optional footer hint row (matches mockup's "Step N of M · Read the docs" line).
     */
    public static function footer(string $hint = '', string $docsUrl = 'https://github.com/nuttaruj/rolepod-wplab'): void
    {
        echo '<div class="rp-footer-hint">';
        if ($hint !== '') {
            echo esc_html($hint) . ' &middot; ';
        }
        echo 'Need help? <a href="' . esc_url($docsUrl) . '" target="_blank" rel="noopener">Read the docs</a>';
        echo '</div>';
    }

    public static function close(): void
    {
        echo '</div>';
    }

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
