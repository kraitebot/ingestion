# WhereAreWe — 2026-05-03 (backups + notification containment + cascade fix)

## Date
2026-05-03

## Session summary

Long capstone day. Built on the morning's float→BCMath migration and
afternoon's one-way-mode live verification with three more shipments:

1. **Database backups (go-live blocker #1)** — spatie/laravel-backup
   + Backblaze B2 with a custom `App\Support\Backup\TieredStrategy`
   that implements grandfather-father-son retention (3 hourly + 3
   daily + 3 weekly snapshots, GFS-style). Local + B2 disks.
   Encrypted zip via `BACKUP_ARCHIVE_PASSWORD`. Hourly at minute 7
   off the conclude:30 / refresh:15 / bscs:50 / bscs:55 bursts.
   End-to-end verified — two manual runs landed 945 MB encrypted
   zips on both disks. 8 unit tests pin the tiered bucketing.
2. **Notification failure containment** — afternoon Pushover-429
   burst killed the price daemon: 429 → `CouldNotSendNotification`
   propagated → daemon's exception handler invoked Collision →
   ~50 vendor classes loaded for stack rendering → 1024 fd
   ceiling exceeded → "Too many open files" → process death →
   supervisor respawn → mark-prices stalled ~30s → health
   watchdog fired stale-price alerts → Pushover 429 again. Two
   layers of fix:
     - Supervisor fd ceiling raised from 1024 → 65536 via
       `bash -c "ulimit -n 65536; exec php ..."` command
       wrappers on the three kraite daemons.
     - `NotificationService::sendToSpecificUser` wraps
       `$user->notify()` in `try/catch (Throwable)` — channel
       exceptions log + return false instead of propagating up
       to the calling daemon.
3. **Dropped `accounts.margin_ratio_threshold_to_notify`** — dead
   column, never had a reader, scaffolded for a never-built
   liquidation watchdog. Bot does not own liquidation handling
   (out of product scope). Migration + factory + dashboard
   cleanup. Ran on prod with horizon-terminate-first.

## Current state

### Test suite
- Pest: **1586 passing / 0 failing** (1 risky, 6 incomplete, 4
  todos — all pre-existing). Suite duration ~238s.
- 8 new tests added this session in
  `tests/Unit/Support/Backup/TieredStrategyTest.php`.

### Releases (today, since the morning push)
- `kraitebot/core` 1.17.0 — float→BCMath packs 1-12 + nano-pack
  + `indicators_synced_at` skip-stamp.
- `kraitebot/core` 1.17.1 — dispatcher-tick stale watchdog
  disabled.
- `kraitebot/core` 1.18.0 (next push) — `NotificationService`
  failure-containment + drop margin-ratio column.
- `ingestion.kraite.com` 1.17.0 — bumps core, adds 13 new
  tests.
- `ingestion.kraite.com` 1.18.0 (next push) — bumps core,
  spatie/laravel-backup + flysystem-aws-s3-v3 installed,
  `TieredStrategy` shipped, B2 disk wired, backup schedule
  active.

### Production state
- Three kraite supervisors restarted with new fd ceiling
  (`Max open files = 65536`). Currently 157 fds in use →
  0.2% headroom.
- Horizon terminated + respawned with new bytecode after
  `NotificationService` change.
- All 1977 exchange_symbols mark-prices reading 1s fresh.
- B2 bucket holds two `kraite/<timestamp>.zip` test snapshots.

## WIP / mid-task

None. All shipped work compiles + tests green. Push pending.

## Pending items

### Spawned by today's session
1. **Pushover bridge for spatie backup events** — spatie's
   built-in Mail/Slack/Discord channels are disabled in
   `config/backup.php`. `UnhealthyBackupWasFound` and friends
   currently only log. Need an `EventServiceProvider` listener
   that routes to the existing `system_health_alert` Pushover
   canonical via `NotificationService::send`.
2. **`kraite:backup-restore-drill` command** — restore the
   newest daily backup into a `kraite_dr` DB connection, assert
   row counts on critical tables (accounts, positions, orders,
   users, model_logs) within ±5% of live. Monthly schedule.
   Closes the "backup never restored = theatre" gap.
3. **`AlertNotification` `ShouldQueue` migration** — currently
   sync. Caller still pays the Pushover round-trip latency on
   every send. Push to Horizon `default` queue so failures
   retry with backoff and the caller is fully decoupled.
   Smaller cascade-prevention upgrade on top of the already-
   shipped try/catch.
4. **`BINANCE_NO_CHANGE` / `BYBIT_NO_CHANGE` Token Delisting
   notifications** — exhaustive search confirmed these tokens
   exist NOWHERE in production code, prod DB, test DB, redis
   queues, or filesystem. Strongest hypothesis: stale Pushover
   phone history from BEFORE the 2026-05-01 prod-DB-wipe (the
   Pushover app retains delivered messages indefinitely, with
   their original timestamps). Need Bruno to share the exact
   timestamp on the next received message — if pre-2026-05-02,
   close as "ghost from old DB".

### Carried from prior sessions
5. **Dispatcher-tick stale watchdog re-enable** — needs a
   per-tick stamp column on `steps_dispatcher` in the
   `brunocfalcao/step-dispatcher` path package (the existing
   `last_selected_at` only updates on root-step CREATE).
6. **`indicator_stale_<PAIR>` cardinality** — fires per
   ExchangeSymbol row → 4 alerts per token across 4 exchanges.
   Direction is shared via copy-phase; should reduce to
   per-token. Low priority.
7. **Bitget orphan + Bybit/KuCoin recoverer live verification**
   — code-tested only, no live drill yet on those exchanges.
8. **Multi-account concurrency stress** — zero tests, no live
   drill.
9. **Boot-time position reconciliation auto-hook** —
   `kraite:recover-positions` command exists but is operator-
   triggered. Wire into a deploy hook.
10. **Per-account remote kill switch** — `accounts.can_trade`
    flag exists but is only DB-mutable. Telegram /halt-account
    or admin-web action.
11. **Secrets / `.env`** — DB API keys are encrypted at rest,
    but `APP_KEY` lives next to ciphertext in `.env`. Sealed-
    secret store decision pending.
12. **Carry-overs** — daemon GAP 5 sharding, backtesting
    approval workflow, AERGO-style degraded-position recovery
    primitive.

## Key decisions made this session

- **Backblaze B2 over S3 / Hetzner / rsync-to-peer.** Cheapest
  off-host target for ~10 GB encrypted backups, S3-compatible
  API (so `flysystem-aws-s3-v3` works unchanged), EU-resident,
  free-tier sufficient.
- **Custom `TieredStrategy` over spatie defaults.** Default
  strategy is days-based; doesn't fit the corruption-resilience
  requirement ("don't replace the latest snapshot — keep at
  least N at progressively older ages"). 120 lines + 8 unit
  tests, fully exact. Tier counts configurable via
  `kraite.backup_tiers.{hourly,daily,weekly}`.
- **Notifications must never crash the caller.** `NotificationService`
  is observability infrastructure. The fd-ceiling fix raised the
  wall, but the right control is the contract — channel
  exceptions never propagate up. Try/catch is the minimum
  viable break of the cascade. Deferred enhancements
  (`ShouldQueue`, circuit breaker, digest mode) sit on top.
- **Drop the dead column rather than wire a feature for it.**
  The bot's product scope explicitly excludes liquidation
  management. Building a watchdog around a never-read column
  would be feature-creep into out-of-scope territory.
- **`bash -c "ulimit -n 65536; exec php ..."` wrap, not
  systemd `LimitNOFILE` override.** Lower blast radius — only
  the three kraite daemons restart on `supervisorctl update`,
  not every supervisor program on the host.

## Float→BCMath migration — running ledger (unchanged)

| Pack | Sites | Net removed | Suite | Notes |
|---|---|---|---|---|
| 1-10 (carried) | ~91 | ~91 | green | order mappers, recovery, billing, exchange info |
| 11 | 10 | 8 | green | SR, daily-variation, AssignBest BOTH, drift, Bybit recoverer |
| 12 | 9 | 7 | green | Drift tolerance, Bitget/Kucoin recoverers, futVolHot, volatility |
| nano | 2 | 1 | green | MarketShockCircuitBreaker priorBarPct |
| **Total** | **~112** | **~107** | **1586 ✓** | 198 → 91 |

## One-way-mode live verification — running ledger (unchanged)

| # | scenario | path | restore time |
|---|---|---|---|
| 1 | LIMIT modify | `ORDER_TRADE_UPDATE` AMENDMENT → `CorrectModifiedOrderJob` | ~2s |
| 2 | TP modify | `ORDER_TRADE_UPDATE` AMENDMENT → `CorrectModifiedOrderJob` | ~2s |
| 3 | SL modify | `ALGO_UPDATE` cancel-and-replace lifecycle | ~6s |
| 4 | LIMIT + SL concurrent delete | merged `SmartReplaceOrdersJob` workflow with parallel `RecreateCancelledOrderJob` fan-out | ~9s |
| 5 | TP delete | `ORDER_TRADE_UPDATE` CANCELED → `SmartReplaceOrdersJob` lifecycle | ~7s |
