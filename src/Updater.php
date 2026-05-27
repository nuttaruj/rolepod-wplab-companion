<?php
declare(strict_types=1);

namespace Rolepod\Wp;

/**
 * GitHub-based plugin auto-updater.
 *
 * Polls the `releases/latest` endpoint on github.com/nuttaruj/rolepod-wp at
 * the cadence WordPress sets for the plugin update transient (default 12h).
 * When the remote tag is newer than ROLEPOD_WP_VERSION, the standard
 * WP admin "Update available" notice and the one-click update button on
 * Plugins → Installed Plugins both work — the package URL points at the
 * stable `releases/latest/download/rolepod-wp.zip` asset produced by
 * scripts/build-zip.sh on every tag push.
 *
 * No server required, no third-party library bundled.
 *
 * Cache: the GitHub API response is stored in a 6-hour transient
 * (`rolepod_wp_github_release`) so admin page loads don't repeatedly hit
 * the GitHub API. Rate limit on unauthenticated calls is 60 req/hour
 * per IP — well above this plugin's footprint.
 */
final class Updater
{
    private const REPO = 'nuttaruj/rolepod-wp';
    private const PACKAGE_URL = 'https://github.com/nuttaruj/rolepod-wp/releases/latest/download/rolepod-wp.zip';
    private const CACHE_KEY = 'rolepod_wp_github_release';
    private const CACHE_TTL = 6 * HOUR_IN_SECONDS;

    public static function init(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [self::class, 'checkUpdate']);
        add_filter('plugins_api', [self::class, 'pluginInfo'], 10, 3);
    }

    /**
     * Inject an update record into the WP plugin-update transient when
     * GitHub has a newer tag than the locally installed version.
     */
    public static function checkUpdate($transient)
    {
        if (!is_object($transient)) {
            return $transient;
        }
        $remote = self::fetchLatest();
        if ($remote === null) {
            return $transient;
        }
        if (!version_compare($remote['version'], ROLEPOD_WP_VERSION, '>')) {
            return $transient;
        }

        $pluginFile = plugin_basename(ROLEPOD_WP_FILE);
        $update = (object) [
            'id'            => 'rolepod-wp/' . basename(ROLEPOD_WP_FILE),
            'slug'          => 'rolepod-wp',
            'plugin'        => $pluginFile,
            'new_version'   => $remote['version'],
            'url'           => 'https://github.com/' . self::REPO,
            'package'       => self::PACKAGE_URL,
            'tested'        => get_bloginfo('version'),
            'requires'      => '6.0',
            'requires_php'  => '7.4',
        ];

        if (!isset($transient->response) || !is_array($transient->response)) {
            $transient->response = [];
        }
        $transient->response[$pluginFile] = $update;
        return $transient;
    }

    /**
     * Provide the "View details" popup data that the Plugins screen
     * requests when the admin clicks the version number in the update
     * notice. Without this filter the popup would 404 against w.org.
     */
    public static function pluginInfo($result, string $action = '', $args = null)
    {
        if ($action !== 'plugin_information') {
            return $result;
        }
        if (!isset($args->slug) || $args->slug !== 'rolepod-wp') {
            return $result;
        }
        $remote = self::fetchLatest();
        if ($remote === null) {
            return $result;
        }

        $changelogHtml =
            '<p><a href="https://github.com/' . self::REPO . '/blob/main/CHANGELOG.md" target="_blank" rel="noopener">Full CHANGELOG.md on GitHub</a></p>' .
            '<p><a href="' . esc_url($remote['url']) . '" target="_blank" rel="noopener">Release notes for v' . esc_html($remote['version']) . '</a></p>';

        return (object) [
            'name'              => 'Rolepod for WordPress',
            'slug'              => 'rolepod-wp',
            'version'           => $remote['version'],
            'author'            => '<a href="https://github.com/nuttaruj">nuttaruj</a>',
            'author_profile'    => 'https://github.com/nuttaruj',
            'homepage'          => 'https://github.com/' . self::REPO,
            'short_description' => 'WordPress companion for the rolepod-wplab MCP server.',
            'sections'          => [
                'description' => 'See the <a href="https://github.com/' . self::REPO . '" target="_blank" rel="noopener">README on GitHub</a> for full details.',
                'changelog'   => $changelogHtml,
            ],
            'download_link'     => self::PACKAGE_URL,
            'requires'          => '6.0',
            'tested'            => get_bloginfo('version'),
            'requires_php'      => '7.4',
            'last_updated'      => $remote['published_at'],
        ];
    }

    /**
     * Force-clear the cached GitHub response. Call after a successful
     * upgrade so the next admin pageview re-checks instead of waiting
     * for the cache window to expire.
     */
    public static function clearCache(): void
    {
        delete_transient(self::CACHE_KEY);
    }

    /**
     * @return array{version:string, url:string, published_at:string}|null
     */
    private static function fetchLatest(): ?array
    {
        $cached = get_transient(self::CACHE_KEY);
        if (is_array($cached) && isset($cached['version'])) {
            return $cached;
        }

        $res = wp_remote_get(
            'https://api.github.com/repos/' . self::REPO . '/releases/latest',
            [
                'timeout' => 10,
                'headers' => [
                    'Accept'     => 'application/vnd.github.v3+json',
                    'User-Agent' => 'rolepod-wp/' . ROLEPOD_WP_VERSION,
                ],
            ]
        );
        if (is_wp_error($res)) {
            return null;
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        if ($code !== 200) {
            return null;
        }
        $body = json_decode((string) wp_remote_retrieve_body($res), true);
        if (!is_array($body) || empty($body['tag_name'])) {
            return null;
        }

        $data = [
            'version'      => ltrim((string) $body['tag_name'], 'v'),
            'url'          => (string) ($body['html_url'] ?? 'https://github.com/' . self::REPO . '/releases'),
            'published_at' => (string) ($body['published_at'] ?? ''),
        ];
        set_transient(self::CACHE_KEY, $data, self::CACHE_TTL);
        return $data;
    }
}
