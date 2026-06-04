# WhereAreWe — 2026-06-04 (docs refresh + review fixes, post-v1.53.2)

## Date

2026-06-04

## Session summary

Documentation refresh (2026-06-02) — reconciled the functional docs in
`~/Herd/docs/kraite/` and the syntax docs site at
`~/Code/syntax.kraite.test/` against the v1.53.x series and the
pheme web-host split.

Review-fix pass (2026-06-04) — an xhigh code review of the docs refresh
surfaced 12 findings; all were fixed: logical queue `pheme-web` renamed
to `web` (double-prefix bug), local `servers` row for pheme seeded,
retro tag `core v1.51.1` created, prod pheme wiring verified live over
SSH (claims below corrected to match), stale doc fragments repaired,
syntax repo committed, test suite re-run after the composer.lock bump.

Triggering changes since the last refresh (hemera onboarding,
2026-05-30):

- **v1.52.0** — hemera joined the trading pool (already covered by the
  previous WhereAreWe snapshot).
- **v1.53.0** — queue convention flipped to `{hostname}-{logical}`,
  env-aware StepRouter candidate pool, `deploy.sh` daemon-restart step.
- **v1.53.1** — tyche capacity bump: `indicators` 10→20, `cronjobs`
  3→20, `tyche` 1→5; tyche subscribed to the `priority` lane (5 procs)
  so stale tyche-bound steps promoted by
  `steps:recover-stale --recover-dispatched` can land back home instead
  of leaking 100% to trading workers.
- **v1.53.2** — changelog tag.
- **kraitebot/core v1.51.1 / v1.51.2** — `kraite.fleet.servers.pheme`
  added (web role split off athena) + full `kraite.horizon.workers`
  map including pheme. (v1.51.1 retro-tagged 2026-06-04: the release
  shipped 2026-06-01 with a CHANGELOG entry + ingestion lock pin
  `429e02a`, but the tag was never created — sequence jumped
  v1.51.0 → v1.51.2.)
- **Pheme horizon block (ingestion `4e73af6`)** — web pool (2 procs)
  + `pheme` probe queue (1 proc) added to `config/kraite.php`.
  Verified live on pheme 2026-06-04: all three web apps run
  `QUEUE_CONNECTION=redis` with PER-APP Horizon supervisors
  (`kraite-horizon-admin` / `-console` / `-kraite`), each under its
  own Redis prefix. Admin + kraite.com resolve `HORIZON_ENV=pheme`;
  console doesn't load kraitebot/core, has no `HORIZON_ENV`, and runs
  its stock `production` block on the `default` queue (self-consistent).
  Pheme stays out of the StepRouter candidate pool, so trading work
  never lands there. Latent gap: admin + kraite.com dispatch to their
  `default` queue, which their Horizon doesn't consume —
  `REDIS_QUEUE=pheme-web` pending (deploy-notes entry 68). All web
  queues empty today; nothing rotting.
- **Logical queue rename `pheme-web` → `web` (2026-06-04)** — the old
  logical name double-prefixed through `{hostname}-{logical}` to
  physical `pheme-pheme-web`. Renamed in ingestion `config/kraite.php`
  + core package config so the physical queue is `pheme-web` — the
  name every doc already used. Ships with the next core tag + deploy;
  pheme's Horizons subscribe to the old physical name until then.

## Docs updated

- `~/Herd/docs/kraite/00-context/server-preparation.md` — Horizon
  topology table now includes pheme column + `pheme-web` row, tyche
  numbers corrected (indicators 20, cronjobs 20, priority 5, tyche 5),
  fleet-wide total recomputed (88 → 127 procs ≈ 254 sustained
  hyperion-side connections), tyche priority-leak rationale inlined,
  pheme-web pool documented.
- `~/Herd/docs/kraite/00-context/system-overview.md` — pheme row in the
  hostname matrix updated (HORIZON_ENV=pheme, pheme-web pool 2 procs),
  queue-and-worker layout table now carries pheme column.
- `~/Code/syntax.kraite.test/src/app/docs/subsystems/horizon-queues/page.md`
  — “seven boxes” framing, eight-queue list (added `pheme-web`),
  per-server table refreshed, fleet process total recomputed, callout
  added for the priority-lane leak and the tracked
  `priority-trading`/`priority-cron` split.
- `~/Code/syntax.kraite.test/src/app/docs/servers/tyche/page.md` —
  Process counts updated (20/20/5/5), capacity-bump rationale (v1.53.1)
  noted, dedicated `Why tyche subscribes to priority` callout added.
- `~/Code/syntax.kraite.test/src/app/docs/servers/pheme/page.md` —
  Flipped the “No Horizon (deferred)” framing to reflect the live
  `pheme` supervisor; pheme-web and pheme pools documented.
- `~/Code/syntax.kraite.test/out/` — rebuilt via `npm run build`.
  Smoke-checked: horizon-queues, tyche, pheme all return 200 from
  https://syntax.kraite.test/. Committed in the syntax repo on
  2026-06-04 (was sitting uncommitted in the working tree).

Review-fix pass additions (2026-06-04):

- `~/Herd/.credentials/kraite/servers.json` — pheme services list now
  carries the three per-app Horizon supervisors; pheme notes rewritten
  for the live redis/per-app posture (deferred/sync note was stale);
  stale queue counts fixed (eos/iris → 5/8/3/1, tyche → 20/20/5/5).
- `~/Herd/.credentials/kraite/deploy-notes.md` — "Horizon for pheme-web
  is deferred" section rewritten to the live per-app model; entry 68
  added (double-prefix rename, latent default-queue gap, kraite.com
  Redis-namespace sharing, local servers-row drift, retro tag).
- `~/Herd/docs/kraite/00-context/system-overview.md` — Applications
  table corrected (admin / kraite.com / syntax now hosted on pheme, not
  athena; console row added), pheme fleet row rewritten for per-app
  Horizons, queue-table row relabelled ``web (physical `pheme-web`)``,
  latent-gap paragraph added.
- `~/Herd/docs/kraite/00-context/server-preparation.md` — stray
  "respawn all 88 workers" corrected to 127 (missed in the 06-02 pass).

## Docs NOT touched

- `~/Herd/.credentials/kraite/hardening.json` — no hardening change in
  this cycle.
- Lifecycle / domain chapters on the syntax site — v1.53.x changes are
  infrastructure-shape only; position / order / token lifecycles are
  unchanged.
- Dynamic-command library files — no behaviour changes since the
  `kraite-tag` / `kraite-update-docs` / `kraite-profile` family already
  cover the pheme profile and the `{hostname}-{logical}` queue
  convention.

## Current state

- Fleet: 8 boxes online (hyperion + athena + eos + iris + nyx + hemera +
  tyche + pheme).
- Latest tags: `ingestion v1.53.2`, `kraitebot/core v1.51.2` (plus the
  retro `v1.51.1`).
- Horizon pools live on athena, eos, iris, nyx, hemera, tyche, pheme
  (pheme = three per-app instances, not one ingestion-checkout pool).
- Total Horizon procs: 127 fleet-wide; ~254 sustained hyperion-side
  connections against `max_connections=256`. **Risk: 2-connection
  headroom.** One tinker session + one mysqldump can exhaust the pool
  fleet-wide. Raise `max_connections` (or trim pools) before the next
  capacity bump.
- Uncommitted in this repo: composer.lock minor/patch bumps (15
  packages, constraint chain verified internally consistent, zero
  dev-* additions, core/step-dispatcher/helpers untouched) + the
  `web` logical-queue rename in `config/kraite.php`. Test suite re-run
  after the bump: 2164 passed, 4 todos, 0 failures (`composer
  test:unit`, kraite_tests DB).
- Local `servers` table re-seeded via `KraiteSeeder::seedServers()` —
  pheme row was missing and the drift gate failed locally. Prod table
  verified complete over SSH.
- Known imperfection: `priority` queue resolver randomly picks among
  5 supervisors (4 trading + tyche), so a tyche-bound stale step
  promoted via `steps:recover-stale --recover-dispatched` still leaks
  to a trading worker 4/5 of the time. Tracked follow-up:
  `priority-trading` vs `priority-cron` per-category split.

## Pending items

- **Tag + deploy the `web` logical-queue rename.** Core package config
  + ingestion config both renamed; pheme's per-app Horizons keep
  subscribing to `pheme-pheme-web` until their checkouts pull the new
  core and restart.
- **`REDIS_QUEUE=pheme-web` on admin + kraite.com (pheme).** Closes the
  latent default-queue gap once the rename is deployed. Prod `.env`
  edit + horizon restart — needs explicit approval.
- **kraite.com Redis namespace decision.** `APP_NAME=Kraite` + no
  `REDIS_PREFIX` = shares the trading fleet's queue/cache namespace.
  Decide whether it gets its own prefix before it ever dispatches jobs.
- **Full hardening per `~/Herd/.credentials/kraite/hardening.json`.**
  Carried over from the hemera session: auditd, rkhunter, aide, lynis
  85+ audit, umask 027 baseline verification, password aging — not yet
  applied fleet-wide (hemera included). Separate workstream.
- **Bybit account for `bruno@nidavellir.trade`.** Carried over: local
  `.env.traders` has `TRADER_BB_*` credentials but the seeder only
  creates the Binance account (Bruno's "Binance only" decision). If
  Bybit comes back, add a sibling Account row in
  `BusinessSeeder::seedBrunoNidavellirTrader()`.

## Next session pointers

- If/when the `priority-trading` / `priority-cron` split lands, refresh
  the same three syntax chapters (`horizon-queues`, `tyche`, `eos-iris`)
  and the two functional docs (`server-preparation`, `system-overview`)
  to drop the leak callout.
- If the pheme queue wiring changes (REDIS_QUEUE fix, console
  HORIZON_ENV, kraite.com prefix), update the pheme server chapter and
  deploy-notes entry 68 accordingly.
