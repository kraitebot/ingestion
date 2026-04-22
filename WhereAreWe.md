# Where Are We - Project Status Handoff

## Last Updated: January 31, 2026 (BitGet TP/SL Recreation Fix)

---

## What Was Done This Session

### 1. BitGet TP/SL Recreation Fix

**Problem Discovered:**
- When recreating cancelled TP/SL orders on BitGet, orders appeared in the
  **"Trigger" tab** instead of the **"TP/SL" tab**
- Orders showed as "Partial SL-Market" with quantity instead of
  "Position SL-Market / All closable"

**Root Cause:**
- Initial implementation used `place-tpsl-order` with `planType: loss_plan`
- This creates individual orders with fixed quantity, not position-level orders

**Fix Implemented:**
- Use `place-pos-tpsl` endpoint with only the relevant TP or SL parameters
- API accepts partial params - can set only SL without affecting existing TP
- Must OMIT `executePrice` parameter for market execution (not set to '0')
- After successful placement, query position for new order ID

### 2. Files Modified

| File | Change |
|------|--------|
| `MapsPlaceTpslOrder.php` | Uses `place-pos-tpsl` with partial params |
| `InteractsWithApis.php` | Added `fetchTpslOrderIdFromPosition()` method |
| `BitgetApi.php` | Added `placeTpslOrder()` (kept for reference) |
| `BitgetApiDataMapper.php` | Added trait import |

### 3. E2E Testing Results

| Test | Result |
|------|--------|
| Delete SL on exchange → sync-orders | ✅ Recreated as "Position SL-Market" |
| Delete TP on exchange → sync-orders | ✅ Recreated as "Position TP" |

---

## Current State

### Code Quality
- All Order tests passing (118 tests, 449 assertions)
- PHPStan has pre-existing type hint warnings (not new)

### What's Working

| Feature | Binance | BitGet |
|---------|---------|--------|
| Position creation | PASS | PASS |
| Order sync | PASS | PASS |
| Cancel position orders | PASS | PASS (individual) |
| Cancel algo orders | PASS | PASS |
| Close position workflow | PASS | PASS |
| **TP/SL recreation** | PASS | **PASS (fixed this session)** |

### Active Test Accounts

| Account | Status |
|---------|--------|
| Binance | **Disabled** (for focused BitGet testing) |
| BitGet | Active |

---

## Key Decisions Made

| Decision | Rationale |
|----------|-----------|
| Use `place-pos-tpsl` for recreation | Creates position-level orders matching original behavior |
| Omit executePrice param | Setting '0' causes API error 43011 |
| Query position for order ID | `place-pos-tpsl` doesn't return orderId directly |

---

## Architecture: BitGet TP/SL Recreation

```
RecreateCancelledOrderJob
      │
      └── apiPlace()
            │
            └── Is BitGet + is_algo?
                  │
                  └── YES → apiPlaceTpslOrder()
                              │
                              ├── preparePlaceTpslOrderProperties()
                              │     ├── Set only SL params (for STOP-MARKET)
                              │     └── Set only TP params (for PROFIT-LIMIT)
                              │
                              ├── placePosTpsl() API call
                              │
                              └── fetchTpslOrderIdFromPosition()
                                    └── Query position for stopLossId/takeProfitId
```

---

## Next Steps

1. **Re-enable Binance account** - BitGet testing complete

2. **Remove test exception from ActivatePositionJob** - If still present

3. **E2E test WAP on Binance** - Fill a LIMIT order to trigger WAP workflow

4. **Test TP/SL recreation on Binance** - Verify Algo Order API handles this

5. **Add Bybit/KuCoin exchange variants** - Follow BitGet pattern if needed

---

## Verification Commands

```bash
# Run Order tests
php artisan test --compact --filter="Order"

# Create fresh positions
php artisan horizon:terminate
php artisan cronjobs:create-positions --clean

# Sync orders (after deleting TP/SL on exchange)
php artisan cronjobs:sync-orders --clean

# Check recent steps
SELECT id, class, state, error_message
FROM steps ORDER BY id DESC LIMIT 20;

# Check MASK algo orders
SELECT id, type, status, exchange_order_id, is_algo
FROM orders WHERE position_id = 1 AND is_algo = 1;
```

---

## Key Files Reference

| Purpose | File |
|---------|------|
| TP/SL recreation mapper | `ApiDataMappers/Bitget/ApiRequests/MapsPlaceTpslOrder.php` |
| Order API interactions | `Concerns/Order/InteractsWithApis.php` |
| BitGet API client | `Support/Apis/REST/BitgetApi.php` |
| Trading tech design | `docs/02-features/trading/tech-design.md` |

---

## TODO (2026-04-22) — Unify stale-step rescue into step-dispatcher

Today we noticed that we have two overlapping stale-step watchdog commands:

| Command | Package | Watches | Action |
|---|---|---|---|
| `steps:recover-stale` | `brunocfalcao/step-dispatcher` | `Running` state (worker died mid-job) | `Running → Pending` retry or `Running → Failed` if retries exhausted. Skips parents whose descendants are still in-flight. |
| `kraite:cron-check-stale-data` | `kraitebot/core` | `Dispatched` state + stuck `steps_dispatcher.can_dispatch=false` locks | Release wedged dispatcher locks (>30s). Promote stuck Dispatched steps (>5m) to `queue=priority, priority=high`. Send pushover/email if self-healing fails. |

They're not duplicates (different states, different fixes) but together they form the
same conceptual responsibility: **keep the dispatcher unstuck and rescue steps the
workers aren't processing.** Ownership-wise this belongs inside the step-dispatcher
package — the promotion-to-priority-queue trick, the dispatcher-lock self-release and
the notification hook are all generic dispatcher health concerns, nothing Kraite-specific.

**Proposal for tomorrow:**

- Merge the two commands into a single `steps:recover-stale` (or split into
  `steps:recover-running` + `steps:recover-dispatched` + `steps:release-locks`
  sub-behaviours, configurable via flags) living entirely in
  `packages/brunocfalcao/step-dispatcher/`.
- Move the dispatcher-lock release logic into the dispatcher package. It's already
  touching `steps_dispatcher` rows, so it belongs here.
- Move the priority-queue promotion into the dispatcher package. Kraite shouldn't
  be the only app that benefits from this.
- Keep the notification hook pluggable — dispatcher package publishes a
  `StaleStepsDetected` event; Kraite (or any consumer) listens and sends the
  pushover/email. Don't bake a specific notification channel into the package.
- Delete `kraite:cron-check-stale-data`, update `routes/console.php` to schedule
  the consolidated dispatcher command only.

**Why not today:** requires cross-package refactor, event plumbing, and some
thinking about how to keep per-app notification preferences outside the shared
dispatcher package. Logging today's intent so we pick it up cleanly tomorrow.
