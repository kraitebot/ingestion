# Project Memory

> Quick-reference learnings for Claude Code sessions.
> Last updated: 2026-01-31

## Key Patterns

### BitGet TP/SL Order Types
- **Pattern**: Two types exist - position-level (`place-pos-tpsl`) vs individual (`place-tpsl-order`)
- **Why**: Position-level tracks full position, individual has fixed quantity
- **UI Difference**: "Position SL-Market / All closable" vs "Partial SL-Market"
- **Files**: `MapsPlaceTpslOrder.php`, `InteractsWithApis.php`

### BitGet TP/SL Recreation
- **Pattern**: Use `place-pos-tpsl` with partial params (only SL or only TP)
- **Why**: Maintains position-level behavior matching original orders
- **Flow**: `apiPlace()` → `apiPlaceTpslOrder()` → `placePosTpsl()` → `fetchTpslOrderIdFromPosition()`

### BitGet holdSide Parameter
- **Pattern**: Must match POSITION direction, not order side
- **Example**: SELL SL for LONG position = `holdSide: 'long'`
- **Code**: `mb_strtolower($order->position->direction)`

## Critical Rules

- **NEVER set executePrice to '0'** for BitGet place-pos-tpsl - causes API error 43011. OMIT the parameter entirely for market execution.
- **BitGet cancel-all-orders ignores symbol** - use individual cancellation instead (loop through orders)
- **place-pos-tpsl doesn't return orderId** - must query position afterwards for `stopLossId`/`takeProfitId`

## API Quirks

### BitGet
- `executePrice: '0'` → Error 43011 "presetSLExcutePrice must than 0" - OMIT param instead
- `cancel-all-orders` ignores `symbol` param - cancels ALL orders on account
- Position TP/SL orders return `size: "0"` from API - return `null` to avoid false drift
- Position TP/SL cannot be cancelled via `cancel-plan-order` - they close with position

## Workflows

### BitGet Order Sync & Recreation
1. `sync-orders` detects order status = CANCELLED
2. `PreparePositionReplacementJob` dispatches
3. `VerifyPositionExistsOnExchangeJob` checks position exists
4. `SmartReplaceOrdersJob` → `RecreateCancelledOrderJob`
5. `apiPlace()` routes to `apiPlaceTpslOrder()` for BitGet `is_algo` orders
6. `placePosTpsl()` API call with partial params (only SL or only TP)
7. `fetchTpslOrderIdFromPosition()` queries position for new order ID

### BitGet Position Close
1. `ClosePositionJob` detects TP/SL filled
2. `CancelPositionOpenOrdersJob` - uses INDIVIDUAL cancellation (not bulk)
3. `CancelAlgoOpenOrdersJob` - skips position TP/SL (they're attached)
4. `ClosePositionAtomicallyJob` - checks if already closed first

## Error Solutions

| Error | Cause | Solution |
|-------|-------|----------|
| API error 43011 "presetSLExcutePrice must than 0" | Setting executePrice to '0' | OMIT the executePrice parameter entirely |
| Orders from other positions cancelled | BitGet cancel-all-orders ignores symbol | Use individual order cancellation loop |
| "Partial SL-Market" instead of "Position SL-Market" | Used `place-tpsl-order` endpoint | Use `place-pos-tpsl` with partial params |
| Order sync shows quantity drift | Position TP/SL returns size=0 | Return `null` for quantity in mapper |
