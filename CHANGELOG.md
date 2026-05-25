# Changelog

All notable changes to `rolepod-wplab-companion` are documented here. Follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format and [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Companion versions track `@rolepod/wplab` major version.

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
