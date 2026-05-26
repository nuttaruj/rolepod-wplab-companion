<?php
declare(strict_types=1);

namespace RolepodWplabCompanion\Admin;

/**
 * Setup Wizard — copy-paste install snippets for users who haven't set up
 * the Node MCP yet. Lives under Tools → WPLab Setup so it's easy to find.
 *
 * Shows three blocks:
 *   1. Site URL + REST namespace (for ROLEPOD_WPLAB sanity checks)
 *   2. Claude Code / Cursor / Codex / Gemini install commands
 *   3. App Password creation reminder + link
 */
final class SetupWizard
{
    private const SLUG = 'rolepod-wplab-setup';

    public static function register(): void
    {
        add_management_page(
            'WPLab Setup',
            'WPLab Setup',
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

        $mcpCommand = sprintf(
            'rolepod-wplab credentials add %s --username=%s',
            escapeshellarg($host),
            escapeshellarg($username)
        );

        ?>
        <div class="wrap">
            <h1>Rolepod WPLab — Setup Wizard</h1>
            <p>Five-minute setup. Connect this WP site to a Node MCP that any AI CLI (Claude Code, Cursor, Codex, Gemini) can talk to.</p>

            <h2>Step 1 — Create an Application Password</h2>
            <ol>
                <li>Open <a href="<?php echo esc_url($appPasswordsUrl); ?>"><?php echo esc_html($appPasswordsUrl); ?></a></li>
                <li>Name the password <code>rolepod-wplab</code> and click <strong>Add New Application Password</strong>.</li>
                <li>Copy the password — you will only see it once.</li>
            </ol>
            <p class="description">Application Passwords are revocable. If anything goes wrong you can delete it from the same screen.</p>

            <h2>Step 2 — Install the Node MCP locally</h2>
            <pre style="background:#f1f1f1;padding:12px;overflow:auto;">
npm install -g @rolepod/wplab          # one-time install
rolepod-wplab doctor                   # sanity-check your environment</pre>

            <h2>Step 3 — Register this site as a target</h2>
            <pre style="background:#f1f1f1;padding:12px;overflow:auto;">
<?php echo esc_html($mcpCommand); ?></pre>
            <p class="description">When prompted, paste the Application Password from Step 1.</p>

            <h2>Step 4 — Wire the MCP into your AI CLI</h2>

            <h3>Claude Code</h3>
            <pre style="background:#f1f1f1;padding:12px;overflow:auto;">
claude mcp add rolepod-wplab -- rolepod-wplab serve</pre>

            <h3>Cursor</h3>
            <p>Open Settings → MCP → "Add server" and paste:</p>
            <pre style="background:#f1f1f1;padding:12px;overflow:auto;">
{ "command": "rolepod-wplab", "args": ["serve"] }</pre>

            <h3>Codex / Gemini CLI</h3>
            <p>Add the equivalent stdio MCP server entry per each CLI's docs. The binary is <code>rolepod-wplab serve</code>.</p>

            <h2>Step 5 — Smoke test from your AI CLI</h2>
            <p>Ask your AI: <em>"Connect to <?php echo esc_html($host); ?> and run health_check."</em></p>
            <p>You should see <code>db_ok:true, wp_cli_ok:false, rest_ok:true, companion_ok:true</code>.</p>

            <hr>

            <h2>Companion endpoints</h2>
            <p>This plugin contributes runtime introspection + execute-php (opt-in). Configure under <a href="<?php echo esc_url(admin_url('options-general.php?page=rolepod-wplab-companion')); ?>">Settings → WPLab Companion</a>.</p>

            <p><strong>Optional steps:</strong></p>
            <ul style="list-style:disc;margin-left:24px;">
                <li>Turn ON <em>Enable companion REST endpoints</em> only when you actively use the MCP.</li>
                <li>Add this site's hostname to <em>Production hostnames</em> if it serves real visitors — protects you from accidental execute-php.</li>
            </ul>
        </div>
        <?php
    }
}
