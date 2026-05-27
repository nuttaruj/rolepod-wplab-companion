<?php
declare(strict_types=1);

namespace Rolepod\Wp\Admin;

use Rolepod\Wp\Security\PairToken;

/**
 * Setup page — top-level submenu "Setup".
 *
 * Two paths shown on one page:
 *
 *   Quick Start — admin clicks "Generate pair token"; companion mints a
 *     one-time token + builds a ready-to-paste prompt that includes the
 *     CLI-specific install snippets + the rolepod_wp_pair call.
 *
 *   Manual — original step-by-step (App Password + npm install +
 *     credentials add + wire CLI). Kept for users who don't want the
 *     plugin install flow.
 */
final class SetupWizard
{
    public static function register(): void { /* registered via Menu::register() */ }

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

        Shell::open(Menu::SLUG_SETUP);
        ?>

        <div class="rp-card">
            <div class="rp-card-head">
                <div>
                    <h3>Quick Start &mdash; one-click pair</h3>
                    <div class="rp-sub">Generates a single-use token + ready-to-paste prompt for any AI CLI. ~60 sec.</div>
                </div>
                <span class="rp-badge rp-badge-accent">Recommended</span>
            </div>
            <div class="rp-card-pad">
                <?php if ($pairToken === null): ?>
                    <p style="margin:0 0 14px;color:var(--rp-text-muted);max-width:660px;line-height:1.6;">
                        Click below to mint a single-use pair token. We'll build a paste-ready prompt that tells your AI CLI to install rolepod-wplab and call <code>rolepod_wp_pair</code> with the token. No manual App Password copy.
                    </p>
                    <form method="post">
                        <?php wp_nonce_field('rolepod_wp_generate_pair_action', 'rolepod_wp_generate_pair_nonce'); ?>
                        <button type="submit" name="rolepod_wp_generate_pair" class="rp-btn rp-btn-primary">
                            Generate pair token
                        </button>
                    </form>
                <?php else: ?>
                    <?php $prompt = self::buildPrompt($siteurl, $host, $pairToken); ?>

                    <div class="rp-pair-card" style="margin-bottom:16px;">
                        <div class="rp-pair-token">
                            <div class="rp-label">Pair token</div>
                            <div class="rp-value" id="rp-pair-token-value"><?php echo esc_html($pairToken); ?></div>
                        </div>
                        <div style="text-align:right;flex-shrink:0;">
                            <div style="font-size:11px;color:var(--rp-text-muted);">Expires</div>
                            <div class="rp-mono" style="font-size:12.5px;color:var(--rp-text);font-weight:500;"><?php echo esc_html($pairExpiresAt); ?></div>
                        </div>
                        <button type="button" class="rp-btn rp-btn-sm" data-rp-copy="#rp-pair-token-value">
                            <span data-rp-copy-label>Copy token</span>
                        </button>
                    </div>

                    <div style="margin-bottom:8px;display:flex;align-items:center;justify-content:space-between;gap:12px;">
                        <span class="rp-field-label" style="margin:0;">Paste this prompt into your AI CLI</span>
                        <button type="button" class="rp-btn rp-btn-sm rp-btn-primary" data-rp-copy="#rp-pair-prompt">
                            <span data-rp-copy-label>Copy prompt</span>
                        </button>
                    </div>
                    <textarea id="rp-pair-prompt" readonly rows="20" class="rp-input rp-input-mono" style="resize:vertical;"><?php echo esc_textarea($prompt); ?></textarea>

                    <p class="rp-field-hint">
                        Token is <strong>single-use</strong> and expires in 60 minutes. On redeem the companion mints an Application Password named <code>wplab-pair-&lt;timestamp&gt;</code> &mdash; revocable from <a href="<?php echo esc_url($appPasswordsUrl); ?>">profile.php</a> any time.
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <div class="rp-card">
            <div class="rp-card-head">
                <div>
                    <h3>Manual setup</h3>
                    <div class="rp-sub">Generate the App Password yourself, install the MCP locally, wire your CLI.</div>
                </div>
            </div>
            <div class="rp-card-pad">

                <h4 style="margin:0 0 6px;font-size:13.5px;font-weight:600;">1. Create an Application Password</h4>
                <ol style="margin:0 0 18px 18px;padding:0;font-size:13px;line-height:1.7;">
                    <li>Open <a href="<?php echo esc_url($appPasswordsUrl); ?>">profile.php &rarr; Application Passwords</a></li>
                    <li>Name the password <code>rolepod-wplab</code></li>
                    <li>Click <strong>Add New Application Password</strong> and copy the value &mdash; shown only once.</li>
                </ol>

                <h4 style="margin:0 0 6px;font-size:13.5px;font-weight:600;">2. Install the Node MCP locally</h4>
                <div class="rp-codeblock" style="margin-bottom:14px;" id="rp-codeblock-install"><span data-rp-copy-text>npm install -g @rolepod/wplab
rolepod-wplab doctor</span><button type="button" class="rp-codeblock-copy" data-rp-copy="#rp-codeblock-install [data-rp-copy-text]"><span data-rp-copy-label>Copy</span></button></div>

                <h4 style="margin:0 0 6px;font-size:13.5px;font-weight:600;">3. Register this site as a target</h4>
                <div class="rp-codeblock" style="margin-bottom:14px;" id="rp-codeblock-target"><span data-rp-copy-text><?php echo esc_html($mcpCommand); ?></span><button type="button" class="rp-codeblock-copy" data-rp-copy="#rp-codeblock-target [data-rp-copy-text]"><span data-rp-copy-label>Copy</span></button></div>

                <h4 style="margin:0 0 6px;font-size:13.5px;font-weight:600;">4. Wire the MCP into your CLI</h4>
                <div class="rp-codeblock" id="rp-codeblock-wire"><span data-rp-copy-text>claude mcp add rolepod-wplab -- rolepod-wplab serve
# Cursor: Settings &rarr; MCP &rarr; { "command": "rolepod-wplab", "args": ["serve"] }
# Codex / Gemini: add equivalent stdio MCP entry per CLI docs</span><button type="button" class="rp-codeblock-copy" data-rp-copy="#rp-codeblock-wire [data-rp-copy-text]"><span data-rp-copy-label>Copy</span></button></div>

            </div>
        </div>

        <?php
        Shell::close();
    }

    /**
     * Build the ready-to-paste setup prompt. Same content as v2.7 — only the
     * surrounding UI changed.
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
        $lines[] = '';
        $lines[] = '* Codex CLI (marketplace):';
        $lines[] = '    codex plugin install github:nuttaruj/rolepod-wplab';
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
        $lines[] = 'You should see db_ok:true, rest_ok:true, companion_ok:true.';
        $lines[] = '';
        $lines[] = '=== end ===';
        return implode("\n", $lines);
    }
}
