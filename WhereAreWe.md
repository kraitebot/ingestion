# WhereAreWe — 2026-05-13 (code review cycle COMPLETE: reviews 10–24)

## Date
2026-05-13

## Session summary

Sequential pass through reviews 10–24 via the `/code-review` skill,
posture-defended lifecycle. 15 reviews closed end-to-end. Bruno set
batch authorisation mid-session: Discard auto-proceeded, Implement
verdicts shipped without per-finding green-light wait once the
cadence was confirmed.

Cycle is COMPLETE. No code-review markdown files remain in
`~/Herd/docs/kraite/code-reviews/`.

## Reviews processed

| # | Topic | Verdicts | Implements |
|---|-------|----------|------------|
| 10 | Trading engine | 3 Implement / 1 docblock / 8 Discard | dead-method delete + `?string` min_notional + ladder warnings appLog + alt-model docblock |
| 11 | OrderObserver | 4 Implement / 7 Discard | locked re-check + `recreated_from_order_id` lineage + `apiModify` string|int|float|null + WAP follow-up `Steps::usingPrefix` |
| 12 | Recovery | 6 Implement / 8 Discard | dual-prefix freeze/cancel + observer suppression + Phase 4 in-flight guard + multi-exchange key extraction + position-scoped order idempotency + untested-exchange gate + MYSQL_PWD |
| 13 | Drift checker | 5 Implement / 9 Discard | alert-only docs + dead-method delete + snapshot-failed signal + exchange-only position+orders signals + REJECTED filter + multi-exchange normalizeDirection |
| 14 | Exchange retry | 2 Implement / 9 Discard | Guzzle timeouts + Bitget 429 explicit-ban-evidence |
| 15 | Step dispatcher | 6 Implement / 2 Discard | doubleCheck → fail / `VerifyPositionExistsOnExchangeJob` lock+dedupe / `withOnlyFromStatus` + close+cancel wiring / DB backoff / batch-transition log / cache group-scoped |
| 16 | Concurrency | 2 Implement / 6 Discard | CreatePositions + SyncOrders active-step dedupe |
| 17 | Operator safety | 6 Implement / 3 Discard | auth on connectivity-test / `--force` gates on `--override` + `--clean` / dual-prefix safe-to-restart + cooldown / no-clobber recovery freezeTrading / all-prefix warmup |
| 18 | Onboarding | 4 Implement / 5 Discard | secret + key required / WS close+error → closeAndUnsetSlot / DispatchPositionSlots final re-check / balance redaction |
| 19 | Mappers | 3 Implement / 5 Discard | Bitget one-way LONG/SHORT / Binance algo both shapes / `closePosition` DTO field |
| 20 | Exception handling | 3 Implement / 5 Discard | HTTP 200 reorder / sticky forbidden records / auto-flip cooldown |
| 21 | Throttler | 1 Implement / 7 Discard | fail-closed cache failure across all 4 throttlers |
| 22 | Daemon liveness | 4 Implement / 5 Discard | cooldown probes return -1 / dead-letter file_put_contents check / production-role schedule fail-closed / withoutOverlapping on heavy crons |
| 23 | User-data stream | 3 Implement / 4 Discard | account-scoped order resolution / `closePosition` consumed in manual-close detect / idempotency comment migration |
| 24 | HTTP timeouts | 1 contract-pin test / 1 Discard | timeouts already shipped via review-14; pin test added |

Implementation total: ~50 source files patched, 2 new migrations,
11 new TDD test files, 0 regressions.

## Migrations applied (already run on prod kraite DB this session)

- `2026_05_13_080000_add_recreated_from_order_id_to_orders`
- `2026_05_13_090000_align_api_data_stream_idempotency_key_comment`

## Local deploy state at end-of-session

- Horizon: terminated + respawned, pid 49352, "Horizon is running"
- Stream-binance-user-data daemon: pid 49631, daemon log confirms
  account #1 (Karine Binance) initialised post-restart
- Stream-binance-prices daemon: pid 49634, kicked
- Dispatch-daemon: pid 1047 (long-running, picks up new opcode on
  next tick automatically; not hard-restarted)
- Scheduler: pid 1064
- System health pass: 2 pre-existing alerts only
  (`balance_stale_account_3`, `stale_syncing_position_293` —
  both pre-cycle state, not regressions).

## Operator-visible behaviour flips that need awareness

- Throttler fail-CLOSED on Redis outage (was fail-OPEN). Redis
  incident now stops exchange traffic cold.
- Sticky forbidden records — `forbidIpNotWhitelisted` and
  `forbidAccountBlocked` no longer auto-expire after 24h. Cleared
  by the existing success-path self-heal on next valid call after
  the user repairs.
- Auto-flip position mode 10-min cooldown — second flip in window
  refused with warning log.
- doubleCheck exhaustion now fails the step (was silent complete).
- `/connectivity-test/start` and `/connectivity-test/status/*`
  require auth.
- `kraite:recover-positions --override` w/o scope requires `--force`,
  refuses to proceed when snapshot fails (override via
  `--allow-snapshot-failure`).
- `kraite:cron-store-accounts-balances --clean` outside `local`
  requires `--force`.
- `UpdatePositionStatusJob withOnlyFromStatus` guard wired to
  close + cancel orchestrators — stale lifecycle steps no-op
  cleanly when the position has moved on.

## Pending

Release pipeline (`/kraite-release`) currently in flight. Phase 0
docs done. Next: Phase 1 (tests), Phase 2 (tag), Phase 3 (deploy
to athena/apollo/ares/artemis), Phase 4 (health), Phase 5 (cleanup).
