# WhereAreWe — 2026-05-17 (dead-code sweep + reboot tooling)

## Date
2026-05-17

## Session summary

A four-part session, interleaved end-to-end with parallel Codex work on
the balance-for-trading-basis + private-beta-seeding features:

1. Started with the test suite at v1.49.0 — 2 failing tests + 2 Larastan
   errors + 1 risky test + 1 skipped test. Fixed each in turn and
   shipped v1.49.0 → v1.49.3 in a single tag run.
2. Ran `/kraite-release` to push v1.49.3 across the full trading
   fleet (athena + apollo + ares + artemis). Two deploy-script bugs
   surfaced live (composer install/update order, mysqldump privilege
   set); both fixed inline, each got its own patch tag (v1.49.2, v1.49.3).
3. Bruno added a new `/kraite-reboot <hostname>` command. Defined the
   per-host shapes (worker / ingestion / database / web), added sweep
   mode (`/kraite-reboot` with no argument walks the fleet in safe order,
   honours `/var/run/reboot-required`), executed the sweep — apollo,
   ares, athena, zeus all rebooted cleanly.
4. Dead-code audit across ingestion + kraitebot/core. 13 items
   detected, 10 applied, 2 skipped (live or partial-build), 1 reduced
   to a docblock-only fix.

## What shipped

### v1.49.0 → v1.49.3 (ingestion) + v1.46.2 (core)

Initial test-pass + Larastan-pass roll-up:

- **`packages/kraitebot/core/src/Commands/Cronjobs/CreatePositionsCommand.php`** —
  orphan position recovery moved BEFORE the `isReadyToTrade()`
  subscription gate. Pre-fix, a lapsed subscription stranded existing
  `status='new'` positions whose `DispatchPositionJob` step had been
  swept. Recovery is now unconditional; only new-opens stay gated.
  Locked by the existing `CreatePositionsCommandOrphanRecoveryTest`
  Pest spec (4/4 green).
- **`database/migrations/2026_05_14_121530_add_status_to_users_table.php`** —
  added `Builder` type hint to the inner `where()` closure to clear
  two Larastan `method.nonObject` errors.
- **`tests/Unit/BaseQueueableJob/T08_ExceptionTypesTest.php`** — added
  `expect(true)->toBe(true);` to the `Cleans laravel.log` helper to
  silence the risky-test flag (matches every sibling `T0X`).
- **`tests/Feature/Backup/B2DiskRetryConfigTest.php`** — S3Client boot
  test no longer skips when `B2_KEY_ID` is unset; `config()->set()`
  + `Storage::forgetDisk('b2')` inject fake credentials so the SDK
  shape check runs deterministically (no network — boot only).
- **`database/migrations/2026_05_16_000001_add_avatar_to_users_table.php`** —
  nullable `users.avatar VARCHAR(2048)` column added (schema
  groundwork — no application code consumes it yet).
- **`deploy.sh`** (3 patches):
  - **v1.49.1** — backups moved to `$PROJECT_DIR/db-backups/` (was
    `storage/backups/...`). Hard-gates `php artisan migrate` on dump
    exit code AND size ≥ 1KB (catches the silent-empty case). Full
    history retained.
  - **v1.49.2** — `composer update <4 path packages>` runs BEFORE
    `composer install` because the shipped lock has all four kraite
    packages as `dev-master` while production constraints are
    versioned (`^6.0` / `^1.12` / `^1.0`) — only `kraitebot/core`
    carries a `branch-alias`, so the other three packages fail
    `composer install` against the production manifest. The flipped
    order regenerates those four lock entries first.
  - **v1.49.3** — mysqldump now passes `--no-tablespaces` and drops
    `--events`. The `kraite@%` MySQL user lacks `PROCESS` (required
    by MySQL 8's default tablespace dump) and `EVENT` (required by
    `--events`). The new hard-gate caught this on the v1.49.2 athena
    deploy and aborted before migrations ran, exactly as designed.

### v1.46.3 (core) + v1.49.4 (ingestion) — pending tag, CI in flight

The dead-code sweep + Codex's private-beta seeding / website_url /
balance-for-trading-basis work landed in the same commit window. Final
local state passing 2331 tests, 0 failures.

**Dead-code sweep (10 items applied):**

- Frontend leftover island: `resources-backup/` (63 dead Blade
  views/css/js/images), `app/helpers.php` (`theme()` +
  `theme_map_color()` — only consumed by the dead views), and
  `config/theme.php` (only consumer was the dead helper). All
  deleted; `app/helpers.php` removed from composer autoload `files`.
- Empty scaffold directories: `app/{Actions,Console,Enums,Models,Services}/`,
  `tests/Fixtures/`, `lang/` (only `vendor/backup/` translations from
  spatie/laravel-backup). All gone.
- Browser testsuite: `tests/Browser/WelcomeTest.php` was a single
  commented-out test in a `/* */` block. Suite wiring stripped from
  `tests/Pest.php` (`->in('Browser', ...)`) and `phpunit.xml`
  (`<testsuite name="Browser">`).
- `app/Providers/HorizonServiceProvider.php` — defined the
  `viewHorizon` Gate but was never registered in
  `bootstrap/providers.php`, so the Gate definition never ran.
- `routes/web.php` — contained only `declare(strict_types=1);`.
  `bootstrap/app.php` `withRouting()` no longer references it.
- `laravel/ui` composer require — zero `Laravel\Ui\*` imports
  anywhere in ingestion or any consumed package.
- Three unread `kraite.*` config keys: `health_check_secret` (core),
  `indicators.jobs_per_index_batch` (core + ingestion).
- Three unused imports in core: `ApiSystemObserver`
  (`use Kraite\Core\Models\ApiSystem`), `IndicatorObserver`
  (`use Kraite\Core\Models\Indicator`), `ReplacePositionOrdersJob`
  (`use Kraite\Core\Support\Proxies\JobProxy`).
- `Kraite\Core\Http\Controllers\Api\DashboardApiController` — 818-line
  stub returning hardcoded fake data + its four routes
  (`/api/dashboard/{data,stats,positions,positions/{id}}`).
- `ConnectivityTestController::start()` tombstone method + its
  `POST /api/connectivity-test/start` route. The method returned
  HTTP 410 Gone unconditionally; the account-based connectivity
  flow (`startAccount`/`status`/`notifyAccountServer`) replaces it.

**Skipped (not actually dead):**

- `kraite.throttlers.kraken.*` + `kraite.api.url.kraken.*` +
  `kraite.api.keys.kraken.*` config blocks. The throttler + URL
  blocks are unread (no `KrakenThrottler` class exists), BUT the
  `Account` and `Kraite` models have encrypted `kraken_api_key` /
  `kraken_private_key` columns and `ExchangeSymbol` has
  `kraken_min_order_size`. Removing the keys-block broke 32 tests on
  first attempt. Kraken support is half-built at the model layer —
  the config blocks stay until the full implementation lands or is
  formally removed.
- `lifecycle_scenarios*` tables (4 tables shipped by core migrations).
  No core code touches them, BUT models exist in
  `admin.kraite.test` that DO consume them. Not dead — just a
  layering smell (core ships schema for admin's models).

**Doc-only fix:**

- `app/Support/Tests/EchoJob` — referenced by
  `StepDispatcher\Database\Factories\StepFactory::definition()` via
  string FQCN `'App\Support\Tests\EchoJob'`. Updated the class
  docblock to warn about the coupling (IDE/refactor tools will NOT
  pick up renames). Moving the class into the step-dispatcher
  package would require inverting the kraite/core ↔ step-dispatcher
  dependency direction — out of scope.

**Codex's parallel work (merged):**

- balance-for-trading-basis migration + AccountFactory + Account
  model + 4 `Maps*AccountBalanceQuery` mappers + new
  AccountBalanceMapperTest + updates to existing balance / position
  tests.
- KraiteSeeder + BusinessSeeder hardening — seeded users now stamp
  `status=active` and an explicit UUID; testing seeds use
  `127.0.0.1` instead of calling the public IP resolver;
  Resend/ZeptoMail config now syncs after `.env.kraite` loads.
- Account-based connectivity flow (`startAccount` / `status` /
  `notifyAccountServer`) — replaces the deprecated raw-credentials
  `start` endpoint.
- `kraite.website_url` config — derives the public website host
  from `APP_URL` with `admin.*` mapped back to the bare domain. Used
  by legal/marketing links rendered outside the marketing app.

## Production state

### Fleet — all 6 servers healthy, no `/var/run/reboot-required`

Trading fleet on v1.49.3 (deployed via `/kraite-release` earlier this
session):

| Host    | Role             | Uptime since reboot |
|---------|------------------|---------------------|
| athena  | ingestion        | ~1h                 |
| apollo  | worker           | ~2h                 |
| ares    | worker           | ~1h 30m             |
| artemis | indicators       | (not rebooted)      |
| zeus    | database (MySQL) | ~30m                |
| helios  | web              | (not rebooted)      |

### Code

- ingestion `master`: `213e7b9` (docs: dead-code sweep in v1.49.4)
- core `master`: `e967f31` (Docs: record core dead-code sweep)
- step-dispatcher `master`: `f96ed6c` (WIP: snapshot before
  dead-code removal) — unchanged, no new tag
- step-dispatcher tag pointing at HEAD: `v1.12.2`
- core last semver tag: `v1.46.2`
- ingestion last semver tag: `v1.49.3`
- All three repos also carry the rollback tag
  `before-dead-code-removal` from earlier in the session.

## New tooling shipped

- `/kraite-reboot <hostname>` — per-host reboot flow (cool down →
  reboot → async SSH poll via `CronCreate` → health check → warm up).
  Athena flow includes a REST reconciliation step after warmup to
  cover lost user-data WebSocket events during the reboot window.
- `/kraite-reboot` (no argument) — sweep mode. Probes
  `/var/run/reboot-required` across all 6 hosts, builds a TODO
  queue in fixed safe order (workers → ingestion → database → web),
  processes host-by-host with per-host approval still required at
  each pre-flight.

Lives at `~/.claude-personal/commands/kraite-reboot.md`.

## Pending

- **CI on `213e7b9`** is in_progress (run 25976396592). Async cron
  poll `e69d3510` checks every 2 minutes; on green it will tag
  **core v1.46.3** and **ingestion v1.49.4**. On failure it stops
  and reports.
- **`/kraite-deploy`** has NOT been run for v1.46.3 / v1.49.4. The
  fleet is still on v1.49.3. Bruno's call when to ship.
- **Codex is still working** on the same files in parallel — recent
  edits arrived mid-flight from another session/agent and need
  awareness when continuing here. The dead-code commits and
  Codex's commits are interleaved in the git history but don't
  conflict (different file sets in most cases).

## Operator-visible behaviour flips that need awareness

- **Pre-migration DB backup is a HARD GATE.** `deploy.sh` will now
  abort BEFORE `php artisan migrate --force` if mysqldump exits
  non-zero or produces a snapshot under 1KB. Old backups are never
  deleted — full history retained in
  `/home/waygou/ingestion.kraite.com/db-backups/`.
- **`composer install` ordering** — deploys now run `composer update`
  on the four path packages first, then `composer install`. This is
  the only order that works against the dev-master lock + versioned
  production constraints.
- **`POST /api/connectivity-test/start`** (raw-credential connectivity
  test) is GONE. Use the account-based flow at
  `POST /api/connectivity-test/accounts/{account}/start`.
- **`GET /api/dashboard/*` endpoints** are GONE — they returned
  hardcoded fake data.
- **`Account.balance_for_trading_basis`** is a new column (Codex's
  migration `2026_05_16_215000`). Application semantics still
  landing — read the migration + AccountFactory + mapper diffs for
  contract.
