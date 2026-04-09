# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

```bash
# PHP syntax check (no build step, no bundler)
php -l app/Controllers/DisputeController.php

# Regenerate optimized classmap after adding/moving classes
composer dump-autoload -o

# Verify all app/ files parse cleanly
for f in $(find app -name '*.php'); do php -l "$f"; done
```

There are no tests, linters, or build pipelines configured. Assets (JS/CSS) are served raw from `assets/`.

## Architecture

This is a WordPress plugin implementing panel-based vote dispute adjudication for the BCC trust system. Page owners challenge votes; a panel of Gold/Platinum members reviews and votes Accept/Reject.

### Plugin Ecosystem

bcc-disputes sits in a multi-plugin system:

- **bcc-core** (required) — ServiceLocator, contracts (`TrustReadServiceInterface`, `DisputeAdjudicationInterface`), DB helpers, permissions, logging
- **bcc-trust-engine** (optional) — `RateLimiter` for atomic rate limiting; falls back to transients if unavailable
- **PeepSo** (optional) — profile page detection, "Report User" button injection

All cross-plugin calls go through `BCC\Core\ServiceLocator` or `class_exists()` guards. The plugin must not fatal when optional dependencies are inactive.

### Namespace & Autoloading

Namespace root: `BCC\Disputes\` mapped to `app/` via Composer PSR-4. Follow the conventions in `wp-content/plugins/BCC-PLUGIN-ARCHITECTURE.md` for all structural decisions.

### Key Classes

- **`DisputeController`** — REST API handler. Registers 7 routes under `bcc/v1`. Orchestrates dispute submission (validates vote, selects panelists, creates DB rows, emails panelists), panel voting, and user reporting. Uses throttling with trust-engine fallback.
- **`ResolveDisputeService`** — Core resolution logic. Wraps dispute status update + trust-engine adjudication call (`DisputeAdjudicationInterface`) in a DB transaction. Implements pre-commit gate: if the adjudicator is unavailable, rolls back rather than leaving a half-resolved dispute. Fires `bcc_dispute_accepted` / `bcc_dispute_rejected` hooks.
- **`DisputeScheduler`** — Daily cron (`bcc_disputes_auto_resolve`). Auto-resolves disputes older than `BCC_DISPUTES_TTL_DAYS` (7). Majority wins; ties favor the voter (rejected).
- **`DisputeRepository`** — Schema installation only (3 tables via `dbDelta`). Table names resolved via `BCC\Core\DB\DB::table()`.
- **`DisputeAdmin`** — Admin UI under `bcc-trust-dashboard` menu. Detail view with panel vote tally, force-resolve actions.
- **`Logger`** — Delegates to `BCC\Core\Log\Logger` with `error_log()` fallback.

### Data Flow

1. Page owner submits dispute via `POST /wp-json/bcc/v1/disputes`
2. Controller validates ownership, fetches vote via `TrustReadServiceInterface`
3. Selects up to `BCC_DISPUTES_PANEL_SIZE` (5) panelists via `TrustReadServiceInterface::getEligiblePanelistUserIds()`
4. Creates dispute row + panel rows in transaction, emails panelists
5. Panelists vote via `POST /wp-json/bcc/v1/disputes/{id}/vote`
6. When majority reached, `ResolveDisputeService` runs adjudication atomically
7. If no majority after 7 days, `DisputeScheduler` auto-resolves

### Database Tables

Three tables prefixed via `BCC\Core\DB\DB::table()`:
- `bcc_disputes` — dispute metadata, status, panel vote tallies
- `bcc_dispute_panel` — per-panelist assignments and decisions (unique constraint on dispute_id + panelist_user_id)
- `bcc_user_reports` — separate user-reporting system (reason_key enum, admin review workflow)

### Constants

```php
BCC_DISPUTES_VERSION     // '1.1.0'
BCC_DISPUTES_PATH        // plugin_dir_path
BCC_DISPUTES_URL         // plugin_dir_url
BCC_DISPUTES_PANEL_SIZE  // 5 — panelists per dispute
BCC_DISPUTES_TTL_DAYS    // 7 — auto-resolve deadline
```

### REST API

All routes require authentication. Namespace: `bcc/v1`.

| Method | Route | Actor |
|--------|-------|-------|
| POST | `/disputes` | Page owner — submit dispute |
| GET | `/disputes/votes/{page_id}` | Page owner — list votes on page |
| GET | `/disputes/mine` | Page owner — list own disputes |
| GET | `/disputes/panel` | Panelist — list assigned disputes |
| POST | `/disputes/{id}/vote` | Panelist — cast accept/reject |
| POST | `/disputes/{id}/resolve` | Admin — force-resolve |
| POST | `/report-user` | Any user — report another user |

### Shortcodes

- `[bcc_dispute_form]` — dispute management panel (page owners)
- `[bcc_dispute_queue]` — panelist review queue
- `[bcc_report_button]` — report user button (also auto-injected on PeepSo profiles)

## Conventions

- Static classes for stateless DB/utility work; instance classes only when held by Plugin singleton or needing injected state
- Controllers must not store request-specific data in `$this->` properties (singleton-held, shared across calls)
- All DB table names via `BCC\Core\DB\DB::table()`, never hardcoded prefixes
- Bridge files in `includes/` are backward-compat only — never add new logic there
- Boot order in main file: constants → autoloader → bridges → schema → hooks

## Architecture Guardrails (ENFORCED)

These rules are non-negotiable. Run `bash scripts/arch-guardrails.sh` before committing.

### Data Access Rules

1. **Repository-only DB access**: All `$wpdb` usage must be inside `app/Repositories/` classes. No exceptions in controllers, services, admin classes, or templates. Allowed exceptions: schema files (`includes/database/`), migrations, `uninstall.php`.
2. **No SELECT ***: Every query must use explicit column lists. Define a `private const COLUMNS` in each repository matching the table schema.
3. **No template queries**: Templates and render files (`templates/`, `includes/admin/`, `includes/partials/`) must receive data from controllers/services. Zero `$wpdb` in templates.
4. **Bounded queries**: Every SELECT must have LIMIT, or query by unique key (WHERE id = %d), or use IN() with a finite input set, or be an aggregate (COUNT/SUM/AVG).
5. **Cache read-heavy paths**: Repositories with 4+ read methods should use `wp_cache_get/set` with a dedicated CACHE_GROUP constant and TTL. Write methods must invalidate affected cache keys.

### Caching Pattern

```php
private const CACHE_GROUP = 'bcc_<plugin>';
private const CACHE_TTL   = 60; // seconds

// Read: check cache first
$cached = wp_cache_get($key, self::CACHE_GROUP);
if ($cached !== false) return $cached;
// ... query DB ...
wp_cache_set($key, $result, self::CACHE_GROUP, self::CACHE_TTL);

// Write: invalidate after mutation
wp_cache_delete($key, self::CACHE_GROUP);
// For wildcard invalidation, use generation counters (wp_cache_incr)
```

### Running Guardrails

```bash
# Scan this plugin only
bash scripts/arch-guardrails.sh bcc-disputes

# Scan all plugins
bash scripts/arch-guardrails.sh

# Machine-readable JSON output
bash scripts/arch-guardrails.sh --json
```
