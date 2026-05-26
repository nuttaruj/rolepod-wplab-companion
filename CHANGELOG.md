# Changelog

All notable changes to this plugin are documented here. Follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format and [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Plugin versions track `@rolepod/wplab` MCP family. See `MIN_COMPANION_VERSION` in `rolepod-wplab/src/companion/constants.ts` for the floor the MCP client expects.

## [2.3.0] — 2026-05-26 — AI Change Ledger + per-change toggle + panic-revert

### Added — `wp_rolepod_wp_changes` ledger table

Every write the MCP issues through this companion is now captured in a custom
table (`{prefix}rolepod_wp_changes`) with category, subcategory, before-state,
after-state, applied flag, reversible flag, source tool, source session,
and audit_id. Schema installs idempotently on plugin activate.

### Added — 5 new REST endpoints (`/wplab/v1/changes/*`)

- `POST /changes/record` — MCP records a change (session_token + manage_options auth).
- `GET  /changes` — list/filter (category, applied, since_minutes, source_session).
- `POST /changes/toggle` — flip one row's applied flag + run per-category dispatcher.
- `POST /changes/toggle-bulk` — flip N rows in one call (used for bisect).
- `POST /changes/panic` — disable every applied change in the last N minutes.

### Added — per-category toggle dispatchers

`Toggler::apply()` runs the right inverse per category when a row flips:
- `hook` — wrapper-flag flip via `HookWrapper::isApplied()` (instant, zero file change).
- `option` — `update_option($name, $before_value)` (or delete if before-state was absent).
- `post` — `wp_update_post()` with the prior title / content / status / excerpt.
- `layout` — `update_post_meta()` with the prior meta value (Elementor / Divi / Oxygen / Bricks JSON).
- `file` — `file_put_contents()` with the prior content (or delete if it didn't exist).
- `plugin` — `activate_plugin()` / `deactivate_plugins()`.
- `theme` — `switch_theme()`.
- `execute_php` — flagged `reversible: 0` at record time; toggle flips flag but cannot replay inverse.

### Added — Tools → Rolepod WP Changes admin page

Hand-rolled list table with tabs (All / Hooks / Content / Options / Layouts / Files / Plugins / Themes / execute-php), bulk-select checkboxes, per-row Disable/Re-enable buttons, and a 🚨 panic-disable button with a window selector (10 min / 1 hour / 24 hours).

### Added — `HookWrapper` helper

AI-issued hook callbacks emitted by `wp-scaffold` are wrapped in:
```php
if (!\Rolepod\Wp\Audit\HookWrapper::isApplied('<audit_id>')) return;
```
Per-request cache so many wrapped callbacks on the same hook share one DB read.

### Pairs with

- `@rolepod/wplab` v1.6.0 — adds `Bridge.recordChange/queryChanges/toggleChange/toggleChangesBulk/panicChanges` + 4 MCP tools (`rolepod_wp_changes_{query,toggle,toggle_bulk,panic}`) + auto-ledger wrappers on writer tools (post_update, post_create, option_set, file_write) + the new `wp-changes` skill.

## [2.1.0] — 2026-05-26 — Shared-host fix + auto-bootstrap wp-cli

### Fixed

- **SessionToken now uses WP transients** instead of `wp_cache_*`. v2.0.0 stored
  tokens in the WP object cache which on shared hosts (no Redis/Memcached) is
  per-request only, so the handshake-then-call flow could not work — every
  follow-up call returned `INVALID_OR_EXPIRED_TOKEN`. Transients route through
  the object cache when one is present and fall back to wp_options rows when
  not, so tokens now persist across requests on every host.

### Added

- **`POST /wplab/v1/wp-cli/bootstrap`** — idempotent endpoint that fetches the
  pinned `wp-cli.phar` from `raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/`
  and writes it to `wp-content/uploads/wplab-bin/wp-cli.phar`. Sanity-checks
  the response (must contain `__HALT_COMPILER` marker) before saving. MCP
  side calls this automatically the first time a wp-cli call returns
  `WP_CLI_NOT_BUNDLED`, so shared hosts get a working wp-cli without anyone
  SSH'ing in. Verifies file presence on re-call → returns `already_present: true`.

### Pairs with

- `@rolepod/wplab` v1.4.0 — adds `RestTarget.wpCli` + `Bridge.wpCli` +
  `Bridge.fileRead/fileWrite` so RestTarget gets full shell capability via this
  companion. Composite tools (audit_security, diagnose, cron_tool, cache_tool,
  mail_test, user_session_list) now work over REST without needing local/ssh/docker
  target kind.

## [2.0.0] — 2026-05-26 — Rebrand: rolepod-wp

### Breaking

This is a clean break. **Existing installs from v1.x must be deactivated + deleted, then `rolepod-wp` v2.0.0 installed fresh.** No in-place upgrade path is provided. Old `rolepod_wplab_companion_*` option rows are orphaned in the DB on uninstall of the v1.x plugin (the v1.x `uninstall.php` will clean them up if the user uses the WP-admin "Delete" action; deactivation alone leaves them).

Why no migration code:
- Solo project, small install base. Migration code carries its own bug surface for a one-time event.
- v1.x `uninstall.php` already deletes its own option rows on plugin delete, so the cleanest path is "delete v1.x → install v2.0.0".

### Changed — naming

- **GitHub repo**: `nuttaruj/rolepod-wplab-companion` → `nuttaruj/rolepod-wp`. GitHub auto-redirects old clone URLs + browser links indefinitely; old release zips at `…/rolepod-wplab-companion-1.x.0.zip` URLs continue to resolve (under the new repo path via redirect).
- **WP plugin slug** (directory under `wp-content/plugins/`): `rolepod-wplab-companion` → `rolepod-wp`.
- **WP plugin bootstrap file**: `rolepod-wplab-companion.php` → `rolepod-wp.php`.
- **WP plugin display name** (admin Plugins list): `Rolepod WPLab Companion` → `Rolepod for WordPress`.
- **Text domain**: `rolepod-wplab-companion` → `rolepod-wp`.
- **Admin pages**:
  - Settings → WPLab Companion → renamed **Settings → Rolepod for WordPress** (slug `options-general.php?page=rolepod-wp`).
  - Tools → WPLab Setup → renamed **Tools → Rolepod WP Setup** (slug `tools.php?page=rolepod-wp-setup`).
- **PHP namespace**: `RolepodWplabCompanion\` → `Rolepod\Wp\` across all 18 PHP source files.
- **PHP defines**: `ROLEPOD_WPLAB_COMPANION_VERSION` / `_FILE` / `_DIR` / `_NAMESPACE` → `ROLEPOD_WP_VERSION` / `_FILE` / `_DIR` / `_REST_NAMESPACE`.
- **Option keys**: `rolepod_wplab_companion_version` → `rolepod_wp_version`; `rolepod_wplab_companion_config` → `rolepod_wp_config`; `rolepod_wplab_audit_log` → `rolepod_wp_audit_log`; pair-token rows now `rolepod_wp_pair_<hash>` (was `rolepod_wplab_pair_<hash>`); throttle keys now `rolepod_wp_pair_fail_<ip_md5>`.
- **Pair token wire format**: prefix `wplab_pair_<hex>` → `rolepod_wp_pair_<hex>`. MCP redeems any token sent by user; no MCP-side change needed.
- **WP_Error codes**: `rolepod_wplab_disabled` / `_unauthorized` / `_execute_php_disabled` → `rolepod_wp_*` equivalents. (Surfaced only to MCP-side error handling; not part of public API.)
- **Filter hook for plugin_internals**: `rolepod_wplab_introspect_<slug>` → `rolepod_wp_introspect_<slug>`. Third-party integrations registering with the old filter name must rename their `add_filter()` call.
- **Audit directory**: `wp-content/uploads/wplab-audit/` → `wp-content/uploads/rolepod-wp-audit/`. Audit-id prefix: `wplab_audit_<hex>` → `rolepod_wp_audit_<hex>`.
- **Release asset filename**: `rolepod-wplab-companion.zip` → `rolepod-wp.zip`. Stable URL: `https://github.com/nuttaruj/rolepod-wp/releases/latest/download/rolepod-wp.zip`.

### Unchanged (intentionally — preserves wire compatibility with MCP clients)

- **REST namespace** stays `wplab/v1`. Path `/wp-json/wplab/v1/handshake`, `/pair/redeem`, etc. unchanged. No client config update needed beyond pointing at the new install URL.
- All endpoint request/response JSON shapes.
- Authentication model (WP Application Password + session token).
- Production guard semantics. AST screen rules. Audit log entry shape.
- App Password display name minted by Pair endpoint: `wplab-pair-<UTC-timestamp>` (already user-visible in profile.php; renaming would surprise users).

### Pairs with

- `@rolepod/wplab` v1.3+ (MCP-side reads `companion_version` from Pair response; `MIN_COMPANION_VERSION` = 2.0.0 on the MCP build that depends on the new slug for install URL).

### Why rebrand

Plugin is the WordPress arm of the broader [Rolepod ecosystem](https://github.com/nuttaruj/rolepod), parallel to `rolepod-uiproof`. The previous `rolepod-wplab-companion` name double-stamped the `wplab` brand and positioned the plugin as subordinate ("companion") rather than as the WordPress endpoint hub of the ecosystem. The new name leaves room for future Rolepod tooling to share these endpoints without a second plugin install.

## [1.2.0] — 2026-05-26

### Added

- **REST endpoint** `POST /pair/generate` (admin only) — mints a one-time pair token (256-bit, SHA-256 at rest, 60-min TTL, max 5 active per admin).
- **REST endpoint** `POST /pair/redeem` (public, token-authed) — atomic single-use redeem → mints WP App Password named `wplab-pair-<UTC-timestamp>` under issuing admin. Per-IP throttle: 10 failed redeems / hour.
- **Setup Wizard quick-start** — Tools → WPLab Setup → click "⚡ Generate setup prompt" → companion builds a ready-to-paste prompt with per-CLI MCP install snippets (Claude Code / Cursor / Codex / Gemini) + `rolepod_wp_pair` call.

## [1.1.0] — 2026-05-26

### Added

- **Setup Wizard admin page** (Tools → WPLab Setup) — manual step-by-step (App Password + npm install + claude mcp add + credentials add). Foundation for the one-click flow in v1.2.

## [1.0.0] — 2026-05-25 — Stable (schema-frozen)

### Locked

- 8 REST endpoints under `/wp-json/wplab/v1/`: handshake, introspect (5 scopes), execute-php, wp-cli, fs-read, fs-write, php-session, request-observer (+ poll).
- Endpoint request/response JSON shapes.
- Audit log entry shape (timestamp, audit_id, endpoint, user, site_url, result, error?, payload_sha256?).
- Production-guard semantics (siteurl glob match; no override on execute-php / fs-write).
- Session-token TTL default 30 min (admin can override per-install).
- AST screen forbidden-token list (eval, assert, create_function, system, passthru, shell_exec, exec, proc_open, popen, pcntl_*, dl, backtick, dynamic include/require).
- Config schema (endpoints_enabled, execute_php_enabled, production_hosts).
- Capability map advertised in handshake.

### Pairs with

- `@rolepod/wplab` v1.0 — Node-side bridge consumes these endpoints + locks tool surface.

### Maintainer next actions (post-tag)

- Submit to wordpress.org plugin directory (may take weeks; if rejected, GitHub Releases stays the canonical distribution).
- Bundle `wp-cli.phar` in release zip (currently fetched separately).
- Companion v1.x+ adds endpoints only; no breaking changes.


## [0.2.0] — 2026-05-25

### Added

- **REST endpoint** `POST /wp-cli` — proxy to bundled `wp-cli.phar` via PHP `exec()`. Closes shared-hosting gap: users without host-side wp-cli can run wp-cli through companion. Returns 503 with hint when `exec()` disabled or phar missing. Path: `wp-content/uploads/wplab-bin/wp-cli.phar`. Destructive subcommands refused unconditionally on production-matched siteurl.
- **REST endpoint** `POST /fs-read` — scoped file read under ABSPATH. 5 MiB cap. Path traversal blocked via `realpath()` containment check.
- **REST endpoint** `POST /fs-write` — scoped file write under `wp-content/themes/`, `wp-content/plugins/`, `wp-content/uploads/` (extension allow-list) or `wp-config.php` with `confirm_unsafe_path=true` opt-in. Production guard refuses unconditionally. Optional pre-write backup with `.wplab-bak-YYYYMMDD-HHMMSS` suffix.
- **REST endpoint** `POST /php-session` — persistent PHP eval context keyed by session token. Variables persist across multiple calls within the same FastCGI worker via `wp_cache_*`. Same guard chain as execute-php (AST screen + production block + audit log).
- **REST endpoints** `POST /request-observer` + `GET /request-observer/poll` — register a hook + queue observed state (timestamp + arg_count) for mid-request introspection. Polling returns accumulated observations (capped at 100, 10 min TTL).
- **Introspect scope** `plugin_internals` — third-party plugins can register via `apply_filters('rolepod_wplab_introspect_<slug>', [...])` to surface their own state.

### Changed

- **execute-php default** — flipped from OFF to ON on activation. Endpoints master toggle (`endpoints_enabled`) still defaults OFF; admin must enable endpoints first, after which execute-php is available unless toggled off separately. Production guard still unconditional.

### Not yet implemented (v0.3+)

- `nikic/php-parser`-based AST screen (replaces v0.1/v0.2 token-blocklist). Adds composer dep.
- Audit log rolling-file mode (replaces wp_options-backed cap of 1000).
- Bundled wp-cli.phar inside release zip (currently fetched separately).
- Per-session token TTL configurable via admin.

## [0.1.0] — 2026-05-25

### Added

- REST endpoints: `GET /handshake`, `GET /introspect`, `POST /execute-php` (default-disabled).
- AST screen (token-blocklist via `token_get_all()`).
- SessionToken (in-memory via `wp_cache_*`, TTL 30 min).
- ProductionGuard (siteurl glob match).
- Append-only audit log (wp_options + file under `wp-content/uploads/wplab-audit/`).
- Admin Settings page (`Settings → WPLab Companion`).
- `uninstall.php` cleans up options + audit dir.

### Defaults (security-first)

- `endpoints_enabled` = false on activation.
- `execute_php_enabled` = false in v0.1 (becomes true in v0.2 once defences proven).

## [0.0.0] — 2026-05-25

### Added — scaffold only

- Plugin bootstrap, LICENSE (MIT), README, .gitignore.
