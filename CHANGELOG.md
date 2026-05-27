# Changelog

All notable changes to this plugin are documented here. Follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format and [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Plugin versions track `@rolepod/wplab` MCP family. See `MIN_COMPANION_VERSION` in `rolepod-wplab/src/companion/constants.ts` for the floor the MCP client expects.

## [2.6.0] — 2026-05-27 — Recovery guardian mu-plugin

WordPress core's WSOD-protection ships with `set_recovery_mode_email`, an
out-of-band recovery channel. v2.6 layers an analogous mechanism for AI-issued
writes: a tiny self-contained mu-plugin that loads BEFORE regular plugins so
it survives parse errors / fatals in the main rolepod-wp plugin (or in any
theme / 3rd-party plugin that loads after it).

### Added — `guardian/rolepod-wp-guardian.php`

Self-contained mu-plugin (no autoload dep on the main plugin's classes).
Auto-installed into `wp-content/mu-plugins/` on main-plugin activate; removed
on deactivate (Option A tight coupling — "deactivate = off completely").

Registers:

- `register_shutdown_function` — catches FATAL/PARSE/COMPILE errors and
  records `{file, line, message, type, ts, request_uri}` into the
  `rolepod_wp_recovery_recent_fatals` transient (last 10, 24h TTL).
- `GET /wp-json/wplab-recovery/v1/status` — main_alive (via
  `defined('ROLEPOD_WP_VERSION')`), recent_fatals, last_fatal, safe_mode,
  wp_version, php_version, siteurl.
- `POST /wp-json/wplab-recovery/v1/disable-plugin` — rename plugin main file
  to `<file>.disabled`, also calls `deactivate_plugins()` for active_plugins
  cleanup.
- `POST /wp-json/wplab-recovery/v1/disable-file` — scope-checked rename of
  any file under wp-content/{plugins,themes,uploads,mu-plugins} or
  wp-config.php.
- `POST /wp-json/wplab-recovery/v1/restore-file` — reverse.
- `POST /wp-json/wplab-recovery/v1/restore-snapshot` — untar a previously
  captured theme snapshot via PharData.
- `GET /wp-json/wplab-recovery/v1/list-changes` — direct DB read of the
  ledger table (bypasses main companion).
- `POST /wp-json/wplab-recovery/v1/safe-mode` — toggle
  `rolepod_wp_safe_mode` option flag.
- `POST /wp-json/wplab-recovery/v1/clear-fatals` — clear recent-fatals
  transient post-review.

Auth = `current_user_can('manage_options')` via WP-native Application
Password (no session token — main plugin's `/handshake` may be unreachable).

### Added — `Rolepod\Wp\Guardian` lifecycle controller (`src/Guardian.php`)

- `Guardian::install()` — copy `guardian/rolepod-wp-guardian.php` into
  `WPMU_PLUGIN_DIR/` (auto-creates dir if missing, chmod 0644).
- `Guardian::remove()` — silent unlink.
- `Guardian::isInstalled()` / `destinationPath()` / `sourcePath()`.

Wired into main plugin:

- `register_activation_hook` → `Guardian::install()` (alongside ledger DB).
- `register_deactivation_hook` → `Guardian::remove()`.
- `plugins_loaded` priority 5 → re-install if missing (covers WP plugin
  UPDATE which replaces files without reactivating; users on 2.5 → 2.6 get
  the guardian on next request without manual reactivation).
- `uninstall.php` → `Guardian::remove()` + clear recovery transients +
  delete `rolepod_wp_safe_mode` option.

### Added — Settings page section "Recovery guardian (mu-plugin)"

- Shows install status + absolute path.
- [Install] / [Reinstall] / [Remove] buttons (nonce-protected).
- Safe-mode banner when `rolepod_wp_safe_mode` is ON; [Clear safe mode]
  button.
- Recent-fatals table (Time, Type, File:Line, Message, URI) + [Clear
  fatal log] button. Only shows when guardian transient has entries.

### Why mu-plugin (not main plugin) for recovery

WP loads `wp-content/mu-plugins/*.php` BEFORE `wp-content/plugins/*`. If the
main plugin file has a parse error, PHP throws at parse time and the file
never runs — its `register_shutdown_function` would never fire. The
mu-plugin parses + registers its shutdown handler BEFORE PHP attempts the
main plugin parse, so the handler is in memory by the time the fatal
happens. WP's own WSOD-protection (5.2+ Recovery Mode) uses the same
trick — we extend it with a REST recovery channel instead of email-based
recovery links.

## [2.6.5] — 2026-05-27 — Fix WP_Application_Passwords API (use get_user_application_passwords + wp_check_password)

v2.6.4 fixed the pluggable.php load order. Demo retest then exposed the
next layer of the same bug: I had assumed `WP_Application_Passwords` has a
static method `validate_application_password()`. It doesn't. The hostinger
error log confirmed:

```
PHP Fatal error: Uncaught Error: Call to undefined method
WP_Application_Passwords::validate_application_password()
in mu-plugins/rolepod-wp-guardian.php:546
```

The actual WP-core API is:
- `WP_Application_Passwords::get_user_application_passwords(int $user_id): array`
  — returns array of stored items: `[{uuid, app_id, name, password
  (hashed), created, last_used, last_ip}]`.
- The validation logic lives in the `wp_authenticate_application_password`
  FUNCTION (not class method), in `wp-includes/user.php`. That function
  iterates passwords and calls `wp_check_password` on each.

We can't call `wp_authenticate_application_password` directly from
muplugins_loaded because it's hooked into the `wp_authenticate` filter
chain which isn't active until later. So we inline the iterate-and-check
pattern:

```php
$passwords = WP_Application_Passwords::get_user_application_passwords($wp_user->ID);
foreach ($passwords as $item) {
    if (wp_check_password($pass, $item['password'], $wp_user->ID)) {
        // best-effort usage recording
        @WP_Application_Passwords::record_application_password_usage($wp_user->ID, $item['uuid']);
        return $wp_user;
    }
}
```

Side benefit: `record_application_password_usage()` updates `last_used` +
`last_ip` on the password row, matching the normal auth path so the user
sees their recovery-app usage in wp-admin → Users → Application Passwords.

### Architectural lesson

When porting WP-core logic into a custom auth path, copy the FUNCTION
body, not the class method assumption. WP separates auth into
filter-hooked functions (`wp_authenticate*`) that wrap class-method
helpers (`WP_Application_Passwords::*`). Reading WP source is the only
authority — autocomplete / IDE inference of "validate_application_password
must exist" was wrong.

## [2.6.4] — 2026-05-27 — Pre-load pluggable.php BEFORE get_user_by()

Critical fix found during demo test of v2.6.3. Recovery endpoint requests
caused a fatal:

```
PHP Fatal error: Uncaught Error: Call to undefined function get_user_by()
in mu-plugins/rolepod-wp-guardian.php:524
```

Root cause: v2.6.3's `authenticate()` called `get_user_by()` BEFORE
requiring pluggable.php. The require was conditional on
`!function_exists('wp_check_password')`, which fired AFTER the
`get_user_by()` call. Since pluggable.php loads AFTER plugins in
wp-settings.php (NOT before mu-plugins), `get_user_by()` was undefined at
`muplugins_loaded`. v2.6.2 had the same bug — passed the v2.6.0 → v2.6.2
upgrade test only because the OLD v2.6.0 guardian (no early dispatch) was
still in memory in cached workers.

Fix: require `pluggable.php` at the TOP of `authenticate()`, before any
call to `get_user_by` / `wp_check_password` / `wp_set_current_user`.
Comment now explicitly enumerates which functions come from pluggable so
future contributors don't repeat the mistake.

Verified via Hostinger error log:
- Before: 100% WSOD on /wplab-recovery/v1/* with fresh workers.
- After: 200 with valid auth, 401 without.

### Architectural lesson recorded

WordPress boot order at `muplugins_loaded`:
- ✅ Loaded: `wp-load.php`, `wp-includes/load.php`, `wp-includes/functions.php`,
  `wp-includes/formatting.php`, `class-wp-rest-server.php`, REST API,
  `class-wp-application-passwords.php` (via autoload if accessed)
- ❌ NOT loaded: `pluggable.php` (and everything it provides:
  `get_user_by`, `wp_check_password`, `wp_set_current_user`,
  `is_user_logged_in`, `wp_get_current_user`, etc.)
- ❌ NOT loaded: regular plugins (loaded after mu-plugins)
- ❌ NOT loaded: theme (loaded at `setup_theme`)

For any mu-plugin code that needs pluggable functions: require
`wp-includes/pluggable.php` explicitly. Don't rely on autoload for
pluggable — there is no autoload for functions in PHP.

## [2.6.3] — 2026-05-27 — Guardian self-upgrade on plugin update

Fix: v2.6.2 install on a site already running v2.6.0 left the OLD
guardian file in place. The plugins_loaded:5 hook only re-copied when
`Guardian::isInstalled()` was false. With a file already present, that
check returned true and skipped the overwrite — so the user's site kept
running the v2.6.0 guardian even after upgrading the main plugin to
v2.6.2.

### Added — `Guardian::isInstalledAtCurrentVersion()` + `readVersion()`

Reads the `ROLEPOD_WP_GUARDIAN_VERSION` define from the first 4 KB of any
guardian file via regex. Cheap (no eval / no autoload). The
plugins_loaded:5 hook now compares installed-version to bundled-version
and overwrites when they differ. Side benefit: downgrade scenarios also
trigger a reinstall (rolls back the guardian to match plugin code).

### Verified on demo

Hostinger demo: v2.6.0 → v2.6.2 plugin upgrade left guardian at v2.6.0.
After v2.6.3, same upgrade path correctly brings guardian to v2.6.3 on
the first post-upgrade request (the plugins_loaded:5 hook fires
every request and is cheap when no copy is needed).

## [2.6.2] — 2026-05-27 — Best-practice auth hardening (pluggable load order + REDIRECT_HTTP_AUTHORIZATION)

Two correctness fixes in the early-dispatch path.

### Fixed — pluggable.php load order in `rolepod_guardian_authenticate()`

`WP_Application_Passwords::validate_application_password()` internally
calls `wp_check_password()`, which lives in `wp-includes/pluggable.php` —
that file loads AFTER plugins in `wp-settings.php`, so at
`muplugins_loaded` it's NOT available yet. v2.6.1 required it only in the
fallback branch, so the primary Application Password validation was
silently failing on some setups. Fix: require `pluggable.php` BEFORE
calling `validate_application_password()`. This also locks in WP-core's
`wp_check_password` (security plugins can't override the recovery escape
hatch — desirable for a recovery path).

### Added — `REDIRECT_HTTP_AUTHORIZATION` + `getallheaders()` fallbacks

Some Apache RewriteRule configurations strip `Authorization` from
`$_SERVER['HTTP_AUTHORIZATION']` and stash it in
`$_SERVER['REDIRECT_HTTP_AUTHORIZATION']`. Other setups only expose it via
`getallheaders()`. Auth extraction now tries, in order: `PHP_AUTH_USER` →
`HTTP_AUTHORIZATION` → `REDIRECT_HTTP_AUTHORIZATION` → `getallheaders()`
scan. Covers Apache mod_php, Apache FastCGI, Nginx FPM, LiteSpeed.

### Refactored

Split `rolepod_guardian_authenticate()` into two functions:
`extract_basic_auth()` (header parsing) + `authenticate()` (user lookup +
password validation). Easier to test + reason about.

`is_email()` check before `get_user_by('email')` — avoids unnecessary
`get_user_by('login')` then `get_user_by('email')` retry chain. Matches
WP-core's `wp_authenticate_application_password` behavior.

## [2.6.1] — 2026-05-27 — Guardian early dispatch (fixes theme-fatal recovery)

Ships the early-dispatch path that was originally deferred to v2.7. Demo
testing during v2.6.0 confirmed that REST endpoints registered via
`rest_api_init` are unreachable when the boot dies during theme load.

### Added — `muplugins_loaded:PHP_INT_MAX` short-circuit

Guardian now detects recovery URLs at the top of mu-plugin load. If the
request URI matches `/wp-json/wplab-recovery/v1/*` or
`?rest_route=/wplab-recovery/v1/*`, it hooks `muplugins_loaded` at max
priority and short-circuits BEFORE WP loads plugins or themes:

1. Sets `REST_REQUEST` const, parses route from URI.
2. Manual Basic-auth check via `WP_Application_Passwords::validate_application_password()`
   (works pre-init because the class is loaded by `wp-load.php`).
3. Falls back to plain `wp_check_password()` for non-app-password tests.
4. Force-fires `rest_api_init` via `rest_get_server()` so guardian routes
   register early.
5. Builds `WP_REST_Request` from `$_SERVER` + JSON body.
6. Dispatches via `WP_REST_Server::dispatch()`.
7. Emits JSON + `exit` — plugins + theme NEVER load for this request.

Adds `X-Rolepod-Guardian: 2.6.1` response header for client identification.

### Now handled

- Theme `functions.php` parse error / fatal — guardian endpoints reachable.
- Plugin file parse errors (other than the guardian mu-plugin itself).
- Any post-mu-plugin fatal that would otherwise kill the request.

### Limitation reduced from ~40% to ~5%

Real-world WSOD distribution (~95% theme + plugin runtime / load) now fully
recoverable through guardian REST. Remaining 5% (DB-dead, wp-config-broken,
core file missing) still require SSH/FTP/cPanel.

### Known limitation — current dispatch path (PRE-v2.6.1 — KEPT FOR HISTORY)

Guardian REST endpoints register via `rest_api_init`. That action only fires
once WP completes the boot sequence through `init`. Since theme `functions.php`
loads at `setup_theme` (BEFORE `init`), a fatal there means `rest_api_init`
never fires, and the guardian's REST routes are never registered for that
request — even though the mu-plugin code itself loaded and its
`register_shutdown_function` did record the fatal into the transient.

What v2.6 DOES correctly handle:

- Records every FATAL/PARSE/COMPILE error to the 24h transient via
  `register_shutdown_function` (this runs regardless of when in the boot
  cycle the fatal happened).
- Surfaces the fatal log + safe-mode toggle via the Settings page when WP
  loads OK.
- Handles fatals that happen AFTER `rest_api_init` (most runtime errors
  inside REST handlers, action callbacks, scheduled tasks).
- Auto-cleanup lifecycle (copy on activate, remove on deactivate, full
  cleanup on uninstall).

What v2.6 does NOT yet handle:

- Fatals during `setup_theme` / theme `functions.php` load (~40% of
  real-world WSODs). The guardian records them in the transient but its
  REST endpoints are unreachable until WP loads OK again.
- Fatals during plugin file parse (plugin own parse error before
  `plugins_loaded`).

### Doesn't cover (boot-stage fatals)

- DB connection failure (mu-plugins load after DB connect).
- wp-config.php parse error (boot dies before mu-plugins).
- WP core file missing (no boot at all).

For these, fall back to SSH/FTP/cPanel manual recovery — no plugin-level
solution exists.

### Planned for v2.7 — early dispatch from muplugins_loaded

Hook `muplugins_loaded:PHP_INT_MAX` and short-circuit when the URL matches
`/wp-json/wplab-recovery/v1/*` or `?rest_route=/wplab-recovery/v1/*`. At
that hook, WP has loaded core + mu-plugins, all regular plugins are about
to load via `include_once` in sequence, and theme has NOT been touched. We
manually:

1. Verify Application Password via `WP_Application_Passwords::validate_application_password()`.
2. Force-init `rest_get_server()` (which fires `rest_api_init`) — registers
   our guardian routes early.
3. Build `WP_REST_Request` from `$_SERVER` + body.
4. Dispatch via `$server->dispatch($request)`.
5. JSON-encode response + send + `exit`.

This bypasses plugins + theme load entirely for recovery URLs, so the
endpoints stay reachable during ANY post-mu-plugin boot fatal — including
theme-load WSODs. Implementation deferred to v2.7 to ship the
infrastructure pieces first.

### Pairs with

`@rolepod/wplab` v1.9.0 — adds:

- `Bridge.recoveryStatus / recoveryDisablePlugin / recoveryDisableFile /
  recoveryRestoreFile / recoveryRestoreSnapshot / recoveryListChanges /
  recoverySafeMode` — Bridge wrappers for `/wplab-recovery/v1/*`.
- `RecoveryModeError` — thrown by the MCP error layer when main namespace
  returns 5xx and guardian reports `main_alive: false`.
- 7 new MCP tools (`rolepod_wp_recovery_*`). Tool count 82 → 89.

## [2.5.0] — 2026-05-27 — One-time admin login + file disable/enable + execute-php crash recovery

### Added — `POST /wplab/v1/admin/one-time-login`

Mints a 5-min single-use transient + returns a `<siteurl>/?rolepod_wp_otl=<hex>`
URL. WP `init` hook intercepts the param, single-use deletes the transient,
calls `wp_set_auth_cookie()` for the issuing admin, and redirects to a
configurable destination (default: dashboard). Closes the "AI needs admin
UI without exposing password" gap that a third-party plugin's v1.3 admin links opened.

### Added — `POST /wplab/v1/fs-rename`

Scope-checked rename (src + dest must resolve under `wp-content/{plugins,
themes,uploads,mu-plugins}` or be `wp-config.php`). Refuses if dest already
exists (no accidental overwrite). Used by the MCP-side `wp_file_disable` /
`wp_file_enable` toggle pair.

### Changed — execute-php crash recovery + duration_ms

`ExecutePhp::handle()` now:
- Catches `\ParseError` separately (returns line number).
- Catches `\Error` (PHP 7+ fatals like "Class not found") without bringing
  down the request.
- Installs a temporary error handler that captures E_WARNING / E_NOTICE /
  E_USER_WARNING into `php_warnings` array instead of failing eval.
- Restores the prior error-handler chain post-eval.
- Measures `duration_ms` via microtime; previously stubbed at 0.

### Pairs with

- `@rolepod/wplab` v1.8.0 — adds:
  - `Bridge.adminOneTimeLink` / `Bridge.fsRename` methods.
  - `wp_admin_one_time_link` MCP tool (Bridge wrapper).
  - `wp_file_disable` / `wp_file_enable` atomic tools (auto-ledger).
  - `wp_jetengine_{read,write}` adapter pair.
  - `wp_metabox_{read,write}` adapter pair.
  - `wp_pods_{read,write}` adapter pair.
  - `wp_conventions_{get,set}` — structured per-site project style guide
    storage (free equivalent of a third-party plugin Pro's paywalled feature).
  - `wp_post_create` now reports `block_count` from Gutenberg comment-syntax
    scan (informational only).


## [2.4.0] — 2026-05-27 — Theme safety: syntax-check + theme snapshot/restore

### Added — `POST /wplab/v1/syntax-check`

Server-side validator that the MCP-side `file_write` calls BEFORE committing a
PHP or JSON payload. Eliminates the worst class of theme regression: bad PHP
in functions.php (White Screen of Death on every request) and bad JSON in
theme.json (Site Editor white-page).

- PHP: writes the payload to a temp file with `.php` extension, runs
  `php -l <file>` via exec(), parses stdout for "Parse error" / "syntax
  error", returns line number from "on line N".
- JSON: pure `json_decode` + `json_last_error_msg()`, line number from
  offset.
- Refuses with `EXEC_DISABLED` 503 if the host blocks `exec()`; MCP falls
  back gracefully to writing without server validation.

### Added — `POST /wplab/v1/theme/snapshot` + `POST /wplab/v1/theme/restore`

Companion-side tar/untar of theme directories under
`wp-content/uploads/rolepod-wp-theme-snapshots/`. Uses PHP `PharData`
(built-in, no composer). Restore validates the snapshot path is inside the
managed dir to prevent arbitrary-path extract attacks.

Used by the MCP-side `wp_theme_switch_safe` composite tool: snapshot the
CURRENT theme dir → wp-cli theme activate → post-switch health probe →
auto-rollback (re-activate old + untar snapshot) if the new theme breaks
the frontend.

### Pairs with

- `@rolepod/wplab` v1.7.0 — adds:
  - `Bridge.syntaxCheck/themeSnapshot/themeRestore` methods.
  - `wp_file_write` pre-write validators (`.php` → companion syntax-check;
    `.json` → Node-side JSON.parse).
  - `wp_theme_snapshot` + `wp_theme_restore` MCP tools.
  - `wp_child_theme_create` composite — parent → child scaffolding.
  - `wp_theme_switch_safe` composite — snapshot + activate + auto-rollback.
  - `wp_session_start` — issues `source_session` id; auto-threads via env into
    all auto-ledger writes so multi-file work is atomically revertable.
  - Auto cache flush after `theme.json` and `/wp/v2/global-styles/<id>` writes.
  - Auto-ledger on `rest_request` global-styles writes (before-state captured
    via GET first).
  - New `wp-edit-theme` skill (13th); `wp-edit-design` scope narrows to
    page-builders only.

## [2.3.1] — 2026-05-26 — Auto-install ledger schema on upgrade

### Fixed

- `plugins_loaded` hook now auto-installs the ledger schema when the option-stored
  schema version differs from the bundled constant. Without this, sites that
  UPDATED from v2.1 → v2.3 (instead of fresh-activated) had the recorder API
  silently inserting into a missing table — every `record` call returned `ok:true`
  with a fresh audit_id, but `query` returned empty. Caught in e2e on the demo
  site immediately after the v2.3.0 ship.

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
