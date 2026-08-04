# Laravel Best-Practices Progress

Last updated: 2026-08-04

## Purpose

This is the durable control file for applying the shared
`laravel-best-practices` skill to Kraite one bounded behavior scope at a time.
It records what was inspected, what changed, how the change was verified, and
what remains. A previous implementation or green test suite does not count as
reviewed under the new standard until current evidence is recorded here.

Authoritative skill:
`/Users/falcaob/Herd/engineering-standards/skills/laravel-best-practices`

Verified starting stack: Laravel 13.23.0, PHP 8.5.5.

## System boundary

- Ingestion application: `/Users/falcaob/Herd/ingestion.kraite.test`
- Core package: `/Users/falcaob/Herd/packages/kraitebot/core`
- Step Dispatcher package: `/Users/falcaob/Herd/packages/brunocfalcao/step-dispatcher`
- Admin application: `/Users/falcaob/Herd/admin.kraite.test`
- Public web/API application: `/Users/falcaob/Herd/kraite.test`
- Expo/mobile applications are excluded; they require their own rules.

## Status rules

- `Not started`: no current evidence-led pass.
- `In progress`: bounded review or implementation underway.
- `Reviewed`: relevant behavior traced; no justified change found.
- `Applied`: justified changes implemented; verification incomplete.
- `Verified`: implementation and observable behavior passed the recorded gates.
- `Partial`: some relevant scopes passed, others remain.
- `N/A`: proven irrelevant to the recorded scope.

No domain becomes `Verified` from convention, memory, static inspection alone,
or an unrelated green suite. Every claim needs paths plus a verification result.

## Rule-domain coverage

| Domain | Status | Reviewed scopes | Evidence and verification | Remaining |
| --- | --- | --- | --- | --- |
| Architecture | Verified | LBP-001 through LBP-012 | Trading, dispatcher, provider, HTTP, billing, schema, view, and configuration boundaries traced end to end | Final release verification remains separate |
| Routing and controllers | Verified | LBP-008 and LBP-009 | Admin, mobile, public registration, connectivity, billing, and webhook contracts reviewed with cross-tenant tests | — |
| Validation | Verified | LBP-008, LBP-009, and LBP-012 | Validated inputs, credential boundaries, gateway identifiers, and runtime settings verified | — |
| Eloquent | Partial | LBP-001 indicator histories; LBP-002 accounts, positions, symbols, and steps | Run-scoped indicator key, locked slot reservation, open-slot uniqueness, and recovery queries verified | Other model-backed workflows |
| Database performance | Verified | LBP-003, LBP-004, LBP-009, and LBP-010 | Real MySQL schemas, row counts, and EXPLAIN plans measured; one redundant 396K-row index removed | Production plans remain an operational check |
| Advanced queries | Partial | LBP-002 slot reservation and live-workflow lookup | Account row locking, directional aggregate counts, and indexed relatable lookup verified | Other complex reads and locking queries |
| Migrations | Verified | LBP-009 and LBP-010 | Payment-receipt idempotency schema and reversible redundant-index cleanup covered by migrated-schema tests | Deployment not yet run |
| Caching | Partial | LBP-002 cross-account token reservation | Shared atomic-lock and reserved-token behavior inspected with focused coverage | Other locks, throttlers, runtime state, and caches |
| Queues and jobs | Partial | LBP-001 query/conclusion; LBP-002 position-opening workflow | Retry recovery, readiness re-checks, locked slot reservation, assignment, and dispatch deduplication verified | Remaining Core and Step Dispatcher workflows |
| HTTP client | Partial | LBP-001 TAAPI bulk requests | Futures-first request, bounded retry path, Spot fallback, malformed/error handling verified | Other providers and endpoints |
| Events and notifications | Verified | LBP-003, LBP-006, LBP-007, and LBP-009 | Order events, atomic delivery claims, lifecycle channels, and billing notices covered | — |
| Mail | Verified | LBP-007 and LBP-009 | Welcome, password reset, billing, and operational delivery paths included in focused suites | External gateway delivery remains operational |
| Scheduling | Verified | LBP-006 | Every overlapping task has a cadence-bounded lease; operational schedule coverage is green | — |
| Configuration | Verified | LBP-012 | Cached TAAPI secret and Horizon worker environment now drive long-lived runtime paths | Worker reload required on release |
| Collections | Partial | LBP-002 token candidate and scoring pipeline | Candidate exclusion, direction filtering, fallback scoring, and unassigned cleanup verified | Other large and business-critical transformations |
| Blade and views | Verified | LBP-011 | Raw output inventory reviewed; dynamic help is escaped and Markdown renderers escape source; build gate required | — |
| Security | Verified | LBP-002, LBP-005, LBP-008, LBP-009, LBP-011, and LBP-012 | Trading gates, diagnostic redaction, tenancy, webhook identity, output escaping, and secret config boundaries verified | — |
| Error handling | Partial | LBP-001 provider and freshness failures | Transient failures retry; dual stale/no-data becomes silent inconclusive; no stale persistence | Other provider, queue, domain, and HTTP boundaries |
| Testing | Verified | LBP-001 through LBP-012 plus SFP partial-close incident | Red-to-green incident regressions, deterministic two-connection races, 13 controlled source mutations, and fresh TIA suites across every application/package | Built-in mutation runner blocked by Pest/PHPUnit compatibility; controlled mutations completed |
| PHP style | Partial | LBP-001 and LBP-002 runtime paths | Focused changes follow current strict-type and project conventions | Changed files in remaining scopes |

## Scope queue

Scopes are ordered by real-money and operational risk. The order may change
when production evidence identifies a more urgent boundary.

1. `LBP-001` — TAAPI indicator querying, Futures-to-Spot fallback, freshness,
   persistence, and inconclusive recovery.
2. `LBP-002` — position preparation, token selection, direction gates, and
   duplicate-opening protection.
3. `LBP-003` — order placement, cancellation, replacement, stop protection,
   and exchange reconciliation.
4. `LBP-004` — Step Dispatcher execution, retries, locks, stale recovery,
   idempotency, and queue leases.
5. `LBP-005` — exchange HTTP clients, throttling, timeouts, retry safety, and
   provider error mapping.
6. `LBP-006` — Laravel schedules, operational snapshots, health checks, and
   missed/overlapping execution behavior.
7. `LBP-007` — events, notifications, mail, deduplication, and after-commit
   delivery.
8. `LBP-008` — Admin and public API routes, validation, authorization,
   tenancy, serialization, and rate limits.
9. `LBP-009` — registration, billing, payments, webhooks, and wallet ledger.
10. `LBP-010` — shared migrations, query plans, indexes, retention, and large
    dataset processing.
11. `LBP-011` — Blade rendering, frontend data boundaries, escaping,
    accessibility, and build verification.
12. `LBP-012` — configuration, secrets boundaries, cache behavior, and
    long-lived worker reload requirements.

## Scope ledger

| ID | Scope | Status | Skill domains | Findings | Changes | Verification | Release |
| --- | --- | --- | --- | --- | --- | --- | --- |
| LBP-001 | TAAPI indicators and Spot fallback | Verified | Architecture, Eloquent, queues/jobs, HTTP client, error handling, testing, PHP style | No functional or standards defect confirmed | Tracker only; no runtime change | 77 passed, 599 assertions through TIA; production evidence healthy | No runtime release needed |
| LBP-002 | Position preparation and token selection | Verified | Architecture, Eloquent, advanced queries, caching, queues/jobs, collections, security, error handling, testing, PHP style | Retry stranded unassigned slots; queued `new` positions bypassed full readiness after a pause | Retry resumes assignment/cleanup; every pre-open boundary now uses `Account::isReadyToTrade()` | 135 passed, 372 assertions through TIA | Local only; release required |
| LBP-003 | Order lifecycle and reconciliation | Verified | Architecture, Eloquent, database, queues/jobs, events, testing | Active order slots used a check-then-insert race; repeatable-read snapshots could still hide a competing committed order; Bitget replacement took locks in reverse order | Central parent lock plus locking current order read; Bitget now locks position before orders | Deterministic two-connection regression plus 3,450-test full gate | Local only; release required |
| LBP-004 | Step Dispatcher recovery | Verified | Architecture, database, queues/jobs, error handling, testing | Stale Running hydration could reopen a step completed by its worker; the first recheck still reused an old child-tree snapshot | Per-step transaction, row lock, and current state/age/tree read before recovery | 217 passed, 554 assertions package-wide | Local only; release required |
| LBP-005 | Exchange HTTP diagnostics | Verified | HTTP client, security, error handling, testing | Failed request logs retained TAAPI secrets and exchange signatures | Recursive payload and URL sanitization without mutating outbound requests | 20 passed, 107 assertions focused | Local only; release required |
| LBP-006 | Scheduling and monitoring | Verified | Scheduling, operations, testing | 41 frequent commands inherited Laravel's 24-hour overlap lease | Explicit cadence-bounded leases for every overlapping schedule | 26 passed, 197 assertions focused | Local only; release required |
| LBP-007 | Notifications and mail | Verified | Caching, events, notifications, mail, testing | Default database throttle was check-then-send and allowed duplicate delivery | Atomic cache claim plus durable delivery log, with defined cache-failure behavior | 35 passed, 99 assertions focused | Local only; release required |
| LBP-008 | Admin and public HTTP boundaries | Verified | Routing, validation, authorization, tenancy, serialization, rate limits | Connectivity notification accepted non-fleet server rows | Fleet-scoped server resolution before delivery | 22 passed, 69 assertions core; HTTP suites included below | Local only; release required |
| LBP-009 | Registration, billing, webhooks, wallet | Verified | Security, transactions, billing, notifications, migrations, testing | Numeric IDs were not tracked; repeated deposits were rejected or lost; cross-invoice parents produced a 500 | Per-payment receipt ledger, exact delta idempotency, and parent ownership validation | 6 passed, 57 assertions webhook; 412/1,896 admin and 86/432 public full gates | Local only; release required |
| LBP-010 | Schema, indexes, and large data | Verified | Migrations, database performance, advanced queries, collections | A non-unique 4-column index duplicated the unique key on 396,217 histories | Reversible redundant-index removal; measured hot plans retained | 2 passed, 4 schema assertions plus MySQL EXPLAIN evidence | Local only; release required |
| LBP-011 | Blade and frontend boundaries | Verified | Blade, security, accessibility, testing | Reusable form help rendered arbitrary dynamic HTML | Blade escaping enabled; raw-output inventory and existing safe Markdown renderer verified | 37 passed, 240 assertions in affected admin views | Local only; release required |
| LBP-012 | Configuration, cache, and workers | Verified | Configuration, caching, queues/jobs, security, testing | Backtest TAAPI fallback used wrong config key/runtime env; routing bypassed cached Horizon environment | Both paths now use cached application configuration | 19 passed, 28 assertions including routing matrix | Local only; release and worker reload required |

## Completion gate for every scope

1. Confirm current package versions and applicable repository instructions.
2. Trace the behavior end to end across applications and linked packages.
3. Record concrete evidence and challenge every proposed change against actual
   product behavior.
4. Implement only confirmed improvements; do not introduce new product rules.
5. Cover high-risk or incident behavior immediately with regression tests.
6. Run the smallest decisive tests, formatter, static/build checks, and diff
   review required by the touched surface.
7. Update the domain table and scope ledger with exact paths and results.
8. Mark release state separately; local verification is not production proof.

## Scope record template

### `LBP-XXX` — Scope title

- Status:
- Behavior boundary:
- Skill domains applied:
- Paths inspected:
- Evidence:
- Confirmed findings:
- Changes:
- Tests added or updated:
- Commands and results:
- Remaining risk:
- Release state:

### `LBP-001` — TAAPI indicators and Spot fallback

- Status: Verified.
- Behavior boundary: scheduled per-symbol indicator query, Futures-first bulk
  response, selective or complete Spot fallback, freshness decision,
  run-scoped history persistence, direction conclusion, and stale recovery.
- Skill domains applied: architecture, Eloquent, queues/jobs, HTTP client,
  error handling, testing, and PHP style.
- Paths inspected:
  - `vendor/kraitebot/core/src/Jobs/Models/Indicator/QuerySymbolIndicatorsJob.php`
  - `vendor/kraitebot/core/src/Support/TaapiMarketDataFreshness.php`
  - `vendor/kraitebot/core/src/Jobs/Models/ExchangeSymbol/ConcludeSymbolDirectionAtTimeframeJob.php`
  - `vendor/kraitebot/core/src/Commands/Cronjobs/ConcludeSymbolsDirectionCommand.php`
  - `vendor/kraitebot/core/src/Abstracts/BaseApiableJob.php`
  - `vendor/kraitebot/core/src/Support/Apis/REST/TaapiApi.php`
  - `vendor/kraitebot/core/src/Models/IndicatorHistory.php`
  - indicator-history schema and the two focused feature-test files.
- Evidence:
  - Fresh Futures remains authoritative and avoids Spot.
  - Missing, stale, malformed, or failed Futures data invokes Spot; fresh
    Futures indicators remain preserved during selective fallback.
  - A current Spot candle is required before its indicators can be accepted.
  - Histories use a unique symbol/indicator/timeframe/run key, and conclusions
    pin themselves to the query run instead of consuming older or later rows.
  - Dual stale/no-data returns unavailable and clears the symbol for the next
    cycle; real provider failures use the normal bounded retry path.
  - Same-day production evidence showed 20,461 completed query jobs, two final
    Spot-sourced runs, four transient fallback attempts that recovered to fresh
    Futures, zero query failures, and zero `stale_indicator_data` conclusions.
- Confirmed findings: none requiring a runtime change.
- Changes: progress ledger only.
- Tests added or updated: none; existing coverage already exercises the full
  fallback, malformed-response, retry, source-mixing, freshness, run-isolation,
  and recovery matrix.
- Commands and results: focused Pest TIA run passed 77 tests with 599
  assertions; all 77 results replayed from the current dependency graph.
- Remaining risk: provider behavior and production volume remain external and
  must continue to be observed; no current code defect is evidenced.
- Release state: no runtime release required.

### `LBP-002` — Position preparation and token selection

- Status: Verified.
- Behavior boundary: account scheduling, opening readiness, exchange snapshot
  preparation, directional slot reservation, token candidate selection,
  assignment, orphan recovery, and per-position workflow dispatch.
- Skill domains applied: architecture, Eloquent, advanced queries, caching,
  queues/jobs, collections, security, error handling, testing, and PHP style.
- Paths inspected:
  - `vendor/kraitebot/core/src/Commands/Cronjobs/CreatePositionsCommand.php`
  - `vendor/kraitebot/core/src/Jobs/Lifecycles/Account/PreparePositionsOpeningJob.php`
  - `vendor/kraitebot/core/src/Jobs/Models/Account/AssignBestTokensToPositionSlotsJob.php`
  - `vendor/kraitebot/core/src/Jobs/Lifecycles/Account/DispatchPositionSlotsJob.php`
  - `vendor/kraitebot/core/src/Jobs/Lifecycles/Position/DispatchPositionJob.php`
  - `vendor/kraitebot/core/src/Trading/TokenSelection/AccountTokenSelection.php`
  - `vendor/kraitebot/core/src/Trading/TokenSelection/TokenCandidatePoolBuilder.php`
  - account, position, exchange-symbol, BSCS policy, readiness, recovery,
    schema, and focused feature-test paths.
- Evidence:
  - Slot capacity remains protected by a transaction and account-row lock;
    open trading-pair uniqueness also has a database constraint.
  - A committed `new` slot with no symbol already consumes capacity. Before
    this pass, an assignment retry created zero additional slots and returned
    early, permanently leaving the existing slot unassigned.
  - `new` means the position has not crossed `PreparePositionData` into
    `opening`. Before this pass, orphan recovery and queued opening jobs could
    continue after a subscription pause because they checked only the API
    system or duplicated a subset of account/user switches.
  - Candidate tradability, live exchange exclusions, BTC/fallback scoring,
    cross-account reservation, direction matching, and final dispatch
    deduplication remain covered and unchanged.
- Confirmed findings: two real opening-lifecycle defects.
- Changes:
  - Retry now assigns or removes pre-existing unassigned slots even when no
    additional capacity exists, and advances only when assignment succeeded.
  - Orphan recovery, preparation, assignment, slot dispatch, and position
    dispatch now use the central current `Account::isReadyToTrade()` decision.
- Tests added or updated: nine new adversarial cases covering successful
  retry assignment, strict-policy retry cleanup, unrelated-row isolation,
  completed/stopped step outcomes, queued-then-paused execution, and six
  closed readiness gates. Existing fixtures now represent active subscriptions.
- Commands and results: each defect was reproduced red first; final focused
  Pest TIA run passed 135 tests with 372 assertions.
- Remaining risk: exchange state can change after any local check; the actual
  order-placement boundary is reviewed separately in LBP-003.
- Release state: local only; runtime release required after all requested
  scopes and final gates pass.

### `LBP-003` through `LBP-007` — Trading and operational integrity

- Status: Verified through focused red-to-green coverage.
- Behavior boundary: order creation and reconciliation, stale workflow
  recovery, failed provider diagnostics, scheduled overlap protection, and
  multi-channel notification throttling.
- Confirmed findings:
  - Active order limits were checked before insert without locking the parent,
    allowing concurrent duplicate STOP/MARKET/PROFIT/LIMIT rows.
  - A stale Running step could complete after watchdog hydration and then be
    reopened to Pending from the stale model.
  - Failed TAAPI and signed exchange requests persisted credentials in request
    diagnostics.
  - Frequent scheduled tasks inherited a 1,440-minute overlap lease.
  - The default notification throttle checked its durable log before delivery
    without an atomic claim.
- Changes:
  - `Order::createForPosition()` locks the parent position and owns all eight
    production order-creation paths.
  - Stale Running recovery locks and rechecks each candidate immediately
    before transition.
  - `ApiLogSanitizer` redacts nested credentials and signed URL parameters
    while leaving outbound requests unchanged.
  - Every overlapping schedule declares a lease bounded to its cadence.
  - Every throttled delivery takes an atomic cache claim; durable delivery
    logs remain the audit source.
- Tests: adversarial creation-boundary architecture checks, worker/watchdog
  race simulation, sent-vs-logged secret assertions, complete schedule lease
  inventory, and duplicate-delivery regressions.
- Commands and results: LBP-003 12/21, LBP-004 8/30, LBP-005 20/107,
  LBP-006 26/197, and LBP-007 35/99 focused tests/assertions passed.
- Remaining risk: exchange-side races and external delivery/provider state
  cannot be removed locally; all local transitions now fail safely.
- Release state: local only; release required.

### `LBP-008` — Admin and public HTTP boundaries

- Status: Verified.
- Behavior boundary: mobile/API authentication abilities, trader/admin role
  separation, account tenancy, passkey ownership, connectivity status and
  notifications, serialization, validation, and per-route throttles.
- Evidence: cross-user account, connectivity, and passkey paths return denied
  or non-enumerating responses; credentials are reduced to boolean state in
  serialized output; protected mutations require write ability.
- Confirmed finding: an account owner could target any `servers` row through
  the whitelist-notification endpoint, including database/admin/indicator
  nodes outside the connectivity fleet.
- Changes: connectivity server lookup now reuses the service's exact eligible
  fleet query and returns a validation error before delivery otherwise.
- Tests: new non-fleet rejection plus existing owner/admin/guest, status, and
  service fan-out matrix; 22 tests and 69 assertions passed in the focused core
  gate. Admin HTTP boundaries are included in the broad final gate.
- Remaining risk: authenticated first-party clients remain responsible for UI
  sequencing; server authorization no longer trusts that sequencing.
- Release state: local only; release required.

### `LBP-009` — Registration, billing, payments, and wallet ledger

- Status: Verified.
- Behavior boundary: draft registration and activation, one-time handoff,
  signed top-ups, webhook authentication, gateway identity, partial/repeated
  payments, wallet mutation, renewal, and notification.
- Evidence: activation locks user/account rows and is retry-idempotent; wallet
  mutations lock the user and write an append-only balance snapshot; webhook
  signature verification precedes controller execution.
- Confirmed findings: official NOWPayments webhook examples use numeric
  `payment_id` values, while the controller tracked strings only. The provider
  also sends repeated deposits as new payment IDs linked by
  `parent_payment_id`; the old single cumulative field rejected or lost them.
  The first receipt-ledger implementation also allowed a child callback to
  reach the database unique constraint when its parent belonged to another
  invoice, returning 500 instead of rejecting the mismatch cleanly.
- Changes: payment IDs normalize to bounded strings; missing/foreign IDs never
  credit; `payment_receipts` stores one unique idempotency/delta row per
  gateway payment while `payments.credited_amount` remains the invoice total.
  A linked deposit must resolve to the same invoice through either the receipt
  ledger or the original payment row before any invoice or wallet mutation.
- Tests: numeric IDs, partial-to-final deltas, child deposits, duplicate child
  callbacks, unrelated and cross-invoice parents, missing IDs, recovery
  renewal, plan changes, registration concurrency, connectivity, legal
  acceptance, and handoff.
- Commands and results: the cross-invoice case reproduced red as an HTTP 500;
  the corrected webhook file passed 6 tests/65 assertions. The final fresh TIA
  gates passed 412 admin tests/1,906 assertions and 86 public tests/432
  assertions.
- Remaining risk: gateway correctness and settlement remain external; the
  release migration must run before new webhook code receives traffic.
- Release state: local only; release required.

### `LBP-010` — Shared schema and measured query paths

- Status: Verified.
- Behavior boundary: order/position/step/indicator/payment indexes,
  high-volume query plans, migration reversibility, and receipt constraints.
- Evidence: local MySQL contains 108,018 live steps and 396,217 indicator
  histories. EXPLAIN uses state/update indexes for recovery, the unique history
  key for run lookups, position-led order indexes for slot checks, and
  `payments.order_id` for webhooks.
- Confirmed finding: `idx_indhist_es_i_tf_ts` exactly duplicated the column
  order of `idx_unique_indicator_history`, adding write and storage cost with
  no extra query capability.
- Changes: reversible migration drops only the redundant non-unique index;
  the unique business invariant remains. Payment receipts use restrictive
  ownership, unique gateway identity, parent lookup, and invoice-history
  indexes.
- Tests: migrated-schema assertions verify both index removal and payment
  idempotency/index invariants; 2 tests and 4 assertions passed.
- Remaining risk: production cardinality may choose different plans; no
  speculative index was added without measured evidence.
- Release state: local only; release required.

### `LBP-011` — Blade and frontend data boundaries

- Status: Verified.
- Behavior boundary: escaped Blade output, Alpine `x-html`, inline JavaScript
  data, reusable form components, admin system views, and frontend build.
- Evidence: raw-output inventory found constant/generated JavaScript
  expressions and two Markdown renderers that HTML-escape source before adding
  controlled markup. Inline data uses Blade escaping or `@js`.
- Confirmed finding: the reusable form field emitted dynamic help using raw
  Blade output even though every current caller supplies text.
- Changes: help now uses normal Blade escaping.
- Tests: malicious help payload reproduced executable markup red, then escaped
  green; 37 affected view tests and 240 assertions passed. Production build is
  part of the final gate.
- Remaining risk: any future `x-html` renderer must preserve source escaping.
- Release state: local only; release required.

### `LBP-012` — Configuration, cache, and long-lived workers

- Status: Verified.
- Behavior boundary: config-cache compatibility, TAAPI secret fallback,
  Horizon environment routing, cache-dependent coordination, and worker reload.
- Confirmed findings:
  - `TaapiCandlesFetcher` queried nonexistent `services.taapi.secret`, then
    called `env()` at runtime; cached production configuration can make the
    fallback disappear.
  - `StepRouter` called `env()` at dispatch time instead of reading the cached
    Horizon environment.
- Changes: both paths now read their authoritative configuration keys; an
  architecture regression prevents direct runtime environment reads there.
- Tests: cached-secret resolution, cached-environment routing, source boundary,
  and the complete StepRouter matrix passed: 19 tests, 28 assertions.
- Remaining risk: deployed Horizon workers retain loaded code/config until the
  normal release workflow reloads them.
- Release state: local only; release and worker reload required.

## Final verification gates

- Ingestion and linked core: 100% type coverage; fresh TIA passed 3,450 tests,
  skipped 1 intentional test, and completed 11,487 assertions across four
  recreated parallel databases. Pint, Rector dry-run, and Larastan all passed
  with no errors.
- Step Dispatcher: full package gate passed 217 tests and 554 assertions.
- Admin: fresh full TIA passed 412 tests and 1,906 assertions; the production
  Vite build passed.
- Public application: fresh full TIA passed 86 tests and 432 assertions.
- TIA reliability: all three application wrappers now isolate the in-progress
  Laravel-skill symlink transition in a temporary Git index, so local TIA can
  inspect changed files without changing Bruno's working tree. Fresh targeted
  TIA runs passed in ingestion, admin, and public; CI retains its explicit
  non-TIA path.
- Structural gates: Pint passed in every PHP repository touched, all three TIA
  wrappers passed shell syntax checks, ingestion Composer metadata validated
  strictly, and all five repositories passed `git diff --check`.

## Adversarial second audit

- Controlled source mutations: 13 of 13 were killed by the focused regression
  suite, covering retry recovery, readiness, concurrency locks and rechecks,
  diagnostics redaction, schedule leases, notification claims, tenancy,
  webhook identity, Blade escaping, cached configuration, schema cleanup, and
  the order observer's locking current read.
- Order-slot isolation: locking only the parent position did not guarantee
  visibility when an outer MySQL repeatable-read transaction had already
  established a snapshot. The observer now uses a locking current read for
  active order types; a deterministic two-connection regression fails without
  it.
- Lock ordering: Bitget protection replacement previously selected orders for
  update before locking their position, reversing the global position-then-
  orders sequence. It now locks the position first and the test asserts the
  query order.
- Stale-recovery tree race: the first row-lock fix still consulted the child
  blocks captured before locking each parent. A deterministic test inserted a
  committed child between that scan and the lock and proved the parent reopened.
  Recovery now performs a locking current child read after locking the parent,
  and its alert identifies the oldest step actually recovered rather than a
  skipped candidate.
- Test isolation: the first fresh parallel gate found that the two-connection
  regression committed the suite bootstrap `kraite` row. Explicit cleanup was
  added, then the full suite passed against recreated parallel databases.
- Native mutation runner: Pest's mutation plugin currently receives an array
  where its runner expects a PHPUnit coverage object. Both TIA and non-TIA
  mutation invocations fail inside the plugin before executing mutations.
  Controlled source mutations therefore provide the recorded mutation proof.

## Production incident regression — SFPUSDT LONG

- Production evidence: position 4365 stored quantity `718`, while a fresh
  Binance drift check returned `714`. Its profit-limit order was partially
  filled by `4`; the user-data event was recorded, protection orders remained
  healthy, and no API or order drift existed.
- Root cause: quantity synchronization was dispatched only for partially
  filled `LIMIT` entries. Partial fills of `PROFIT-LIMIT`, `PROFIT-MARKET`, and
  `STOP-MARKET` close orders returned before synchronization, and the five-
  minute safety net also filtered to `LIMIT` only.
- Red-to-green coverage: seven new expectations failed before the fix and all
  passed after it. Both event-driven and scheduled paths now synchronize every
  managed partially filled entry/close type; 105 dependent tests and 244
  assertions passed in the expanded incident gate.
- Notification accuracy: drift detection remains alert-only, so notification
  text no longer falsely claims that it dispatched order synchronization.
- Release state: local only at this checkpoint; production quantity remains
  drifted until the requested release completes and the scheduled path runs.
