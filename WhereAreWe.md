# WhereAreWe — Kraite Ingestion

## Date
2026-04-23

## Session summary
Second hardening session after going live on the 12-slot book. Key
work:
- USELESS #64 realized-loss incident traced to ladder-check ordering.
  Pre-gate now simulates the DCA ladder before any market placement.
- Sync-path formatter normalization across all four `apiSync*` paths.
- Opening-failure pushover + auto-block of the symbol via
  `is_manually_enabled=false` on the transition into `failed`.
- New `kraite:disable-volatile-tokens` command + hourly :45 schedule
  sweeping memes / speculative / structural-brittle tokens across
  every api_system. 35 tokens disabled in total this session
  (Binance + Bybit + KuCoin + Bitget).
- Main Binance Account seeded with 6×6 slots; create-positions
  scheduled every 3 minutes — full autonomy live.
- Rate-limit deep dive on KuCoin + Bitget 429 bursts. Root cause:
  `recordIpBan` was being invoked on every soft 429 (not just on
  documented IP bans), which synchronised the fleet into a halt-flood
  oscillation. Fix: gate `recordIpBan` on explicit ban signals only
  (HTTP 418 / 403 / 429-with-Retry-After). Batched the per-symbol
  leverage-bracket fan-out to size 5 via sequential `index` so only
  5 peers race `canDispatch` concurrently instead of 500+.
- `position_opened` and `position_closed` Pushover canonicals muted
  on Bruno's call — too chatty on the 12-slot book. WAP / opening-
  failure / pump-cooldown / residual / high-profit still fire.

## Current state
- `php artisan test` — last run of touched paths (ladder pre-gate +
  sync normalization) 7 passing / 18 assertions. Full suite not re-
  run this session; no known failures.
- Horizon terminated at session end (reloads the new trait + batch
  code). Watch `api_request_logs` at next :15 tick (hourly refresh-
  exchange-symbols cron) for 429 pattern regression.
- `can_trade` flipped to `false` on Main Binance Account at session
  end (Bruno said "stop opening new positions"). 12 existing
  positions continue their lifecycle to TP / SL / WAP normally.

## WIP
Nothing mid-implementation. Earlier in the session we pushed
`ce6760f..890d4e2` (core) and `7337dd6..25e84bd` (ingestion). The
rate-limit fix (recordIpBan gating + index batching) is post-push —
still uncommitted on core as of this snapshot.

Uncommitted files:
- `packages/kraitebot/core/src/Concerns/BaseApiableJob/HandlesApiJobExceptions.php`
  (recordIpBan gating + new `isExplicitIpBanSignal` predicate)
- `packages/kraitebot/core/src/Jobs/Lifecycles/ApiSystem/Bitget/SyncLeverageBracketsJob.php`
  (batch size 5 constant + sequential `index`)
- `packages/kraitebot/core/src/Jobs/Lifecycles/ApiSystem/Kucoin/SyncLeverageBracketsJob.php`
  (same)
- `packages/kraitebot/core/src/Jobs/Lifecycles/ApiSystem/Bybit/SyncLeverageBracketsJob.php`
  (same)
- `packages/kraitebot/core/src/Jobs/Atomic/Position/ActivatePositionJob.php`
  (`position_opened` call commented out)
- `packages/kraitebot/core/src/Jobs/Atomic/Position/UpdateRemainingClosingDataJob.php`
  (`position_closed` call commented out)

## Pending items
- Monitor 429 counts on the next hourly :15 tick to validate the
  rate-limit fix. If oscillation still visible, next lever is atomic
  `canDispatch` via Redis `INCR` token bucket or `Cache::lock` —
  tracked in memory as `taapi_throttler_concurrency_todo.md`.
- The 890 × TAAPI 429s / 24h signal is the same `BaseApiThrottler`
  race, still open. Not blocking; throttler still drains work, just
  at ~17 % of the plan cap.
- `position_opened` / `position_closed` pushovers: revive after a
  digest / quiet-hours filter lands.
- Corrupt `limit_quantity_multipliers` JSON column on
  `exchange_symbols` — root cause of the USELESS trigger is the
  default `[2,2,2,2]` multipliers on a low-price token; separately,
  one dev-DB row was seen historically with a malformed JSON value.
  Source of the corruption unidentified. Pre-gate catches the
  symptom.

## Key decisions this session
- **`recordIpBan` is for explicit IP bans, not soft 429 probes.** The
  TAAPI throttler (no `recordIpBan`) survives its own `canDispatch`
  race gracefully; adding a fleet-halt on every 429 converted that
  graceful pattern into an oscillation on the exchanges that do
  define the method. Policy: `recordIpBan` only on HTTP 418 / 403 /
  429-with-Retry-After.
- **Per-symbol fan-out lifecycles must index-batch.** All-parallel
  fan-out at `index=1` blows through per-exchange rate limits on
  tight-capped APIs (KuCoin 25/3s, Bitget 90/60s). Sequential index
  batching of ~5 peers matches the pool's effective throughput to
  the throttler's usable concurrency. Cascade exposure is
  theoretical (zero `Failed` transitions observed on bracket sync in
  production).
- **Ladder feasibility is checked BEFORE market placement.**
  `VerifyOrderNotionalForMarketOrderJob` is now the authoritative
  pre-gate: if the ladder calculator throws on the projected
  ladder, the workflow aborts before any exchange-side state
  exists. No orphaned MARKET to unwind, no realized loss.
- **Opening-failure side-effects travel together.** On transition
  into `failed`, the symbol is auto-blocked AND the operator is
  notified — never one without the other. Re-entry is guarded so
  retries can't double-fire.
- **Deny-list is source-controlled and additive.** Static constants
  in `DisableVolatileTokensCommand`. Three categories (MEMES /
  SPECULATIVE / STRUCTURAL-BRITTLE) kept as separate constants for
  documentation. Hourly sweep picks up symbols as exchanges list
  them, never re-enables.
