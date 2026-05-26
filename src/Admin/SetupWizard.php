<?php
declare(strict_types=1);

namespace Rolepod\Wp\Admin;

use Rolepod\Wp\Security\PairToken;

/**
 * Setup Wizard — Tools → Rolepod WP Setup.
 *
 * Two paths shown on one page:
 *
 *   Quick Start (v1.2) — admin clicks "Generate setup prompt"; companion mints
 *     a one-time pair token + builds a ready-to-paste prompt that includes
 *     CLI-specific plugin install snippets + the rolepod_wp_pair call.
 *     The AI of choice executes the prompt and auto-pairs.
 *
 *   Manual — original v1.1 step-by-step (App Password + npm install + claude
 *     mcp add + credentials add). Kept for users who don't want the plugin
 *     install flow or are using a CLI that doesn't have a wplab plugin.
 */
final class SetupWizard
{
    private const SLUG = 'rolepod-wp-setup';

    public static function register(): void
    {
        add_management_page(
            'Rolepod WP Setup',
            'Rolepod WP Setup',
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

        $siteurl = get_site_url();
        $host = (string) wp_parse_url($siteurl, PHP_URL_HOST);
        $current_user = wp_get_current_user();
        $username = $current_user instanceof \WP_User ? $current_user->user_login : 'admin';
        $appPasswordsUrl = admin_url('profile.php#application-passwords-section');
        $companionSettingsUrl = admin_url('options-general.php?page=rolepod-wp');

        // Quick Start handler: form post issues a fresh pair token + renders prompt.
        $pairToken = null;
        $pairExpiresAt = null;
        if (
            isset($_POST['rolepod_wp_generate_pair'])
            && check_admin_referer('rolepod_wp_generate_pair_action', 'rolepod_wp_generate_pair_nonce')
        ) {
            $pairToken = PairToken::issue(get_current_user_id());
            $pairExpiresAt = gmdate('Y-m-d H:i:s', time() + PairToken::ttlSeconds()) . ' UTC';
        }

        $mcpCommand = sprintf(
            'rolepod-wplab credentials add %s --username=%s',
            escapeshellarg($host),
            escapeshellarg($username)
        );

        ?>
        <div class="wrap">
            <h1>Rolepod for WordPress — Setup Wizard</h1>
            <p>Connect this WP site to any AI CLI (Claude Code / Cursor / Codex / Gemini). Pick a path below.</p>

            <h2 style="margin-top:32px;">⚡ Quick Start (recommended) — one-click pair</h2>
            <p>Generates a one-time pair token, then builds a ready-to-paste prompt that tells your AI to install the right plugin and connect — no manual App Password copy.</p>

            <form method="post">
                <?php wp_nonce_field('rolepod_wp_generate_pair_action', 'rolepod_wp_generate_pair_nonce'); ?>
                <p>
                    <button type="submit" name="rolepod_wp_generate_pair" class="button button-primary">
                        Generate setup prompt
                    </button>
                </p>
            </form>

            <?php if ($pairToken !== null): ?>
                <?php $prompt = self::buildPrompt($siteurl, $host, $pairToken); ?>
                <p><strong>Pair token (expires <?php echo esc_html($pairExpiresAt); ?>):</strong></p>
                <p><code style="user-select:all;"><?php echo esc_html($pairToken); ?></code></p>

                <p><strong>Copy this prompt into your AI CLI (Claude Code / Cursor / Codex / Gemini):</strong></p>
                <textarea id="wplab-pair-prompt" readonly rows="22" style="width:100%;font-family:Menlo,monospace;font-size:12px;"><?php echo esc_textarea($prompt); ?></textarea>
                <p>
                    <button type="button" class="button" onclick="(function(){var t=document.getElementById('wplab-pair-prompt');t.select();document.execCommand('copy');})();">
                        Copy prompt to clipboard
                    </button>
                </p>
                <p class="description">
                    The token is <strong>single-use</strong> and expires in 60 minutes. On redeem the companion mints an Application Password named <code>wplab-pair-&lt;timestamp&gt;</code> — revocable from <a href="<?php echo esc_url($appPasswordsUrl); ?>">profile.php</a> if you change your mind.
                </p>
            <?php endif; ?>

            <hr style="margin:40px 0;">

            <h2>Manual setup (alternative)</h2>
            <p>Use this if your AI CLI does not have a wplab plugin yet, or you prefer to set up credentials yourself.</p>

            <h3>Step 1 — Create an Application Password</h3>
            <ol>
                <li>Open <a href="<?php echo esc_url($appPasswordsUrl); ?>"><?php echo esc_html($appPasswordsUrl); ?></a></li>
                <li>Name the password <code>rolepod-wplab</code> and click <strong>Add New Application Password</strong>.</li>
                <li>Copy the password — you will only see it once.</li>
            </ol>

            <h3>Step 2 — Install the Node MCP locally</h3>
            <pre style="background:#f1f1f1;padding:12px;overflow:auto;">
npm install -g @rolepod/wplab
rolepod-wplab doctor</pre>

            <h3>Step 3 — Register this site as a target</h3>
            <pre style="background:#f1f1f1;padding:12px;overflow:auto;">
<?php echo esc_html($mcpCommand); ?></pre>
            <p class="description">When prompted, paste the Application Password from Step 1.</p>

            <h3>Step 4 — Wire the MCP into your AI CLI</h3>
            <p>Claude Code:</p>
            <pre style="background:#f1f1f1;padding:12px;overflow:auto;">
claude mcp add rolepod-wplab -- rolepod-wplab serve</pre>
            <p>Cursor: Settings → MCP → "Add server":</p>
            <pre style="background:#f1f1f1;padding:12px;overflow:auto;">
{ "command": "rolepod-wplab", "args": ["serve"] }</pre>
            <p>Codex / Gemini: add equivalent stdio MCP entry per each CLI's docs. Binary: <code>rolepod-wplab serve</code>.</p>

            <hr>

            <h2>Plugin endpoints</h2>
            <p>This plugin contributes runtime introspection + execute-php (opt-in). Configure under <a href="<?php echo esc_url($companionSettingsUrl); ?>">Settings → Rolepod for WordPress</a>.</p>

            <p><strong>Recommended:</strong></p>
            <ul style="list-style:disc;margin-left:24px;">
                <li>Turn ON <em>Enable companion REST endpoints</em> only when you actively use the MCP.</li>
                <li>Add this site's hostname to <em>Production hostnames</em> if it serves real visitors — protects you from accidental execute-php.</li>
            </ul>
        </div>
        <?php
    }

    /**
     * Build the ready-to-paste setup prompt.
     * Includes Claude Code / Cursor / Codex / Gemini CLI install snippets +
     * the rolepod_wp_pair MCP call with siteurl + pair_token baked in.
     */
    private static function buildPrompt(string $siteurl, string $host, string $pairToken): string
    {
        $lines = [];
        $lines[] = '=== rolepod-wplab one-click pair ===';
        $lines[] = '';
        $lines[] = 'I want you to connect to my WordPress site and run rolepod-wplab tools on it.';
        $lines[] = '';
        $lines[] = "Site URL: {$siteurl}";
        $lines[] = "Hostname: {$host}";
        $lines[] = "Pair token (single-use, expires in 60 min): {$pairToken}";
        $lines[] = '';
        $lines[] = '--- Step 1: install rolepod-wplab on your CLI (pick the one you use) ---';
        $lines[] = '';
        $lines[] = '* Claude Code (marketplace, recommended):';
        $lines[] = '    /plugin marketplace add nuttaruj/rolepod-wplab';
        $lines[] = '    /plugin install rolepod-wplab@rolepod-wplab';
        $lines[] = '    (bundles MCP server + 11 skills + slash commands; auto-runs `claude mcp add`)';
        $lines[] = '';
        $lines[] = '* Codex CLI (marketplace, recommended):';
        $lines[] = '    codex plugin install github:nuttaruj/rolepod-wplab';
        $lines[] = '    (or add MCP manually — see fallback below)';
        $lines[] = '';
        $lines[] = '* Cursor (manual MCP entry):';
        $lines[] = '    Settings → MCP → Add server →';
        $lines[] = '    { "command": "npx", "args": ["-y", "@rolepod/wplab", "serve"] }';
        $lines[] = '';
        $lines[] = '* Gemini CLI (manual MCP entry):';
        $lines[] = '    Add to ~/.gemini/settings.json under mcpServers:';
        $lines[] = '      "rolepod-wplab": {';
        $lines[] = '        "command": "npx",';
        $lines[] = '        "args": ["-y", "@rolepod/wplab", "serve"]';
        $lines[] = '      }';
        $lines[] = '';
        $lines[] = '* Fallback (any CLI — global install):';
        $lines[] = '    npm install -g @rolepod/wplab';
        $lines[] = '    then register `rolepod-wplab serve` as a stdio MCP server in your CLI config.';
        $lines[] = '';
        $lines[] = '--- Step 2: pair this site using the token above ---';
        $lines[] = '';
        $lines[] = 'Once the MCP is available, call:';
        $lines[] = '';
        $lines[] = '    rolepod_wp_pair {';
        $lines[] = "      \"siteurl\": \"{$siteurl}\",";
        $lines[] = "      \"pair_token\": \"{$pairToken}\"";
        $lines[] = '    }';
        $lines[] = '';
        $lines[] = 'The pair tool will:';
        $lines[] = '  - exchange the token for a real WP Application Password (companion-minted)';
        $lines[] = '  - store the credential in the local vault (OS keychain when available)';
        $lines[] = '  - open a Target and return target_id + capability list';
        $lines[] = '';
        $lines[] = '--- Step 3: confirm + start working ---';
        $lines[] = '';
        $lines[] = 'After pair returns ok, run:';
        $lines[] = '';
        $lines[] = '    rolepod_wp_health_check { "target_id": "<from-step-2>" }';
        $lines[] = '';
        $lines[] = 'You should see db_ok:true, rest_ok:true, companion_ok:true. From here every';
        $lines[] = 'rolepod_wp_* tool works against this site.';
        $lines[] = '';
        $lines[] = '=== end ===';
        return implode("\n", $lines);
    }
}
