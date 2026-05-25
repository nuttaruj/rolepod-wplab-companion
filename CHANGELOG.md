# Changelog

All notable changes to `rolepod-wplab-companion` are documented here. Follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format and [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Companion versions track `@rolepod/wplab` major version.

## [0.1.0] — 2026-05-25

### Added

- **REST endpoint** `GET /wp-json/wplab/v1/handshake` — returns companion version, capability map, production status, and issues a per-session execution token (TTL 30 min, in-memory).
- **REST endpoint** `GET /wp-json/wplab/v1/introspect?scope=…` — runtime-context snapshot. Scopes: `hooks` (all active actions/filters with priority + callback identifier), `transients` (names + sizes; values opt-in on non-prod), `options_full` (all `wp_options` rows; values opt-in on non-prod with sensitive-key redaction), `request_state` (current request + WP state flags).
- **REST endpoint** `POST /wp-json/wplab/v1/execute-php` — shipped DISABLED by default (admin opt-in via Settings). Even when ON, requires: session token + AST screen clean + production guard pass.
- **AST screen** (`Security/AstScreen.php`) — token-based blocklist using `token_get_all()`. Rejects `eval`, `assert`, `create_function`, `system`, `passthru`, `shell_exec`, `exec`, `proc_open`, `popen`, `pcntl_exec`, `pcntl_fork`, `dl`, backtick operator, dynamic include/require. v0.2 will swap for proper AST via `nikic/php-parser` (composer dep).
- **Session token** (`Security/SessionToken.php`) — issued by handshake, stored in `wp_cache_*` (in-memory), bound to user_id, TTL 30 min, manual revoke.
- **Production guard** (`Security/ProductionGuard.php`) — siteurl glob-pattern match against admin-configured list; refuses execute-php unconditionally on match (no override).
- **Audit log** (`Audit/Log.php`) — append-only. File: `wp-content/uploads/wplab-audit/<audit_id>.log` (mode 0600). Option: `rolepod_wplab_audit_log` (capped 1000 entries, FIFO).
- **Admin Settings page** (`Settings → WPLab Companion`) — master endpoints toggle, execute-php toggle, production-hosts list, audit log tail viewer.
- **uninstall.php** — removes options + audit log directory on plugin delete.
- Manual PSR-4-style autoload — no Composer required.

### Defaults (security-first)

- `endpoints_enabled` = **false** on activation. Admin must explicitly turn endpoints ON.
- `execute_php_enabled` = **false** in v0.1. Even on a fresh install with endpoints enabled, execute-php returns 503 until admin opts in.
- `production_hosts` = empty array. Admin populates per-target.

### Pairs with

- `@rolepod/wplab` v0.1.x — Node MCP handshake probes `/wplab/v1/handshake` at every target-open.

### Not yet implemented (v0.2)

- `nikic/php-parser`-based AST screen (replaces v0.1 token-blocklist).
- Bundled `wp-cli.phar` + `POST /wp-cli` proxy endpoint (closes shared-hosting wp-cli gap).
- `POST /fs-read` + `/fs-write` endpoints (RestTarget file ops without SSH).
- `POST /request-observer` (mid-request introspection).
- `POST /php-session` (persistent eval context).
- New introspect scope `plugin_internals/<slug>` (3rd-party plugin hook).
- Audit log rolling-file mode (replaces single-option cap of 1000).
- Composer + PHPUnit + Pest tests.
- PHP × WP CI matrix.

## [0.0.0] — 2026-05-25

### Added — scaffold only

- Plugin bootstrap, LICENSE (MIT), README, .gitignore.
