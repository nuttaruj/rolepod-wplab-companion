# rolepod-wplab-companion

**Status:** v0.0 — scaffold only. No working endpoints yet. Companion v0.1 lands alongside `@rolepod/wplab` v0.1.

Optional thin WordPress plugin (~300 LOC PHP, MIT) that pairs with [`rolepod-wplab`](https://github.com/nuttaruj/rolepod-wplab) to unlock `execute-php` + runtime introspection on a connected WordPress install. Without this plugin, wplab still works as a default-safe wp-cli + REST + scoped-fs toolkit. With this plugin, wplab reaches the same capability ceiling as a third-party plugin — under stricter, opt-in guardrails.

## When you need this

Install **only if** you want any of:

- `rolepod_wp_execute_php` — run arbitrary PHP inside the live WP request lifecycle.
- `rolepod_wp_introspect` — snapshot active hooks / transients / `wp_options` / request state.
- `rolepod_wp_hook_state` — list callbacks registered on a specific hook.
- (v0.2+) Mid-request observer + persistent PHP eval session.
- (v0.2+) `wp-cli` over REST (bundled wp-cli.phar) — for shared hosts where you cannot install wp-cli normally.
- Adapter write surfaces (Elementor / WooCommerce / ACF) that need WP PHP API access.

For scaffold / audit / health / file-write workflows, the Node MCP (`@rolepod/wplab`) is enough on its own.

## Install (v0.1+)

```bash
# Download latest release zip:
curl -L -o /tmp/rolepod-wplab-companion.zip \
  https://github.com/nuttaruj/rolepod-wplab-companion/releases/latest/download/rolepod-wplab-companion.zip

# Install via wp-cli (or upload via WP admin → Plugins → Add New → Upload):
wp --path=<path-to-your-wp-install> plugin install /tmp/rolepod-wplab-companion.zip --activate

# Toggle endpoints ON via WP admin → Settings → WPLab Companion.
```

Then from the Node MCP side:

```bash
rolepod-wplab doctor    # companion handshake should report 200 OK
export ROLEPOD_WPLAB_PROFILE=power
# reconnect target — power tools register
```

## Architecture

- 3 REST endpoints under `/wp-json/wplab/v1/`: `handshake`, `execute-php`, `introspect`.
- Single admin page: `Settings → WPLab Companion` (activation toggle + production-hostnames + audit log viewer + session-token list + capability map).
- Defence in depth: Application Password auth + session token + **two AST screens** (Node-side AND PHP-side via `nikic/php-parser`) + production-block + confirm-token + append-only audit log.
- No outbound network calls. No phone-home. No telemetry. No SaaS dependency.
- Code budget: **≤ 500 LOC PHP**, lint-enforced.

Full spec lives with the Node MCP brief — see `brief/12-companion-plugin.md` in the parent repo (maintainer-only).

## Versioning

Companion versions track wplab major:

| Companion | Pairs with wplab | Status |
|---|---|---|
| 0.0 | 0.0 | scaffold (this) |
| 0.1 | 0.1 | handshake + introspect endpoints, execute-php disabled by default |
| 0.2 | 0.2 | execute-php default-enabled + bundled wp-cli + fs endpoints + observer + persistent session |
| 0.3 | 0.3 | plugin_internals introspect hook + multisite tested |
| 0.5 | 0.5 | submitted to wordpress.org plugin directory |
| 1.0 | 1.0 | schema-frozen + external-audited |

## Security model

Every guarded operation passes:

1. **WP Application Password verification.**
2. **Per-session token check** (TTL 30 min, bound to user + app pass).
3. **Production hostname block** — if siteurl matches admin-configured pattern, refuse regardless of caller. No override.
4. **AST screen (Node side)** — payload parsed before send; reject `eval`/`system`/`shell_exec`/`exec`/`proc_open`/`popen`/dynamic include/out-of-scope file ops.
5. **AST screen (PHP side)** — re-parsed via `nikic/php-parser` inside this plugin before any eval. Defence in depth.
6. **Append-only audit log** — every call (success + reject) written to `wp_options::rolepod_wplab_audit_log` (capped 1000 entries FIFO) AND file `wp-content/uploads/wplab-audit/<audit_id>.log` (mode 0600).

If you find a security issue, please report privately to `nuttaruj@gmail.com` with a 90-day disclosure window (see `SECURITY.md` once it lands at v0.5).

## License

MIT — see [LICENSE](./LICENSE). Clean-room from [a third-party plugin](https://github.com/use-third-party/third-party) (AGPL-3.0); no a third-party plugin code was read or copied. Same clean-room policy as `rolepod-wplab` itself.

## Repo layout (planned for v0.1)

```
rolepod-wplab-companion/
├── rolepod-wplab-companion.php      # plugin bootstrap (WP plugin header + autoload)
├── src/
│   ├── Endpoint/
│   │   ├── Handshake.php
│   │   ├── ExecutePhp.php
│   │   ├── Introspect.php
│   │   ├── WpCli.php                # v0.2 (bundled phar proxy)
│   │   ├── FsRead.php               # v0.2
│   │   └── FsWrite.php              # v0.2
│   ├── Security/
│   │   ├── AstScreen.php
│   │   ├── SessionToken.php
│   │   └── ProductionGuard.php
│   ├── Audit/
│   │   └── Log.php
│   └── Admin/
│       └── SettingsPage.php
├── vendor/                          # nikic/php-parser bundled (composer install --no-dev)
├── bin/wp-cli.phar                  # v0.2 (bundled, ~5 MB)
├── tests/                           # PHPUnit + Pest
├── composer.json
├── README.md
├── LICENSE
└── CHANGELOG.md
```
