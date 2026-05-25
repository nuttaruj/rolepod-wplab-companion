# Changelog

All notable changes to `rolepod-wplab-companion` are documented here. Follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format and [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Companion versions track `@rolepod/wplab` major version.

## [0.0.0] — 2026-05-25

### Added — scaffold only

- Plugin bootstrap (`rolepod-wplab-companion.php`) with WP plugin header.
- LICENSE (MIT).
- README with install / architecture / security overview.
- `.gitignore` excluding `vendor/`, `node_modules/`, `bin/wp-cli.phar`, `*.zip`.

### Not yet implemented

- REST endpoints (`handshake`, `execute-php`, `introspect`) — v0.1.
- Admin settings page — v0.1.
- AST screen — v0.1.
- Audit log — v0.1.
- Bundled wp-cli.phar — v0.2.
- File-system endpoints — v0.2.
- Request observer + persistent PHP session — v0.2.
- Composer + PHPUnit + Pest setup — v0.1.
- PHP × WP CI matrix — v0.1.
