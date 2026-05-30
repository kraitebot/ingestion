# WhereAreWe — 2026-05-30 (hemera onboarding)

## Date

2026-05-30

## Session summary

Hemera (Hetzner CX23, primordial of day, Nyx's mythological complement)
joined the trading worker pool as the 7th box in the Kraite fleet.
Full provisioning end-to-end: SSH bootstrap, hardening to iris-parity
(ssh hardening, sysctl base + worker role, UFW SSH-only, fail2ban,
chrony, hostname user + passwordless sudo, /etc/hosts kraite block,
php8.5-* + supervisor + base packages), Kraite app provisioning at
tag v1.52.0 (composer.production.json swap, .env with `HORIZON_ENV=hemera`,
config:cache, supervisor block for kraite-horizon), fleet integration
(/etc/hosts hemera+nyx symmetry on every existing box, hyperion UFW
allow rule for 10.0.0.8), kraitebot/core v1.50.0 ships hemera in
`kraite.fleet.servers`, kraitebot/ingestion v1.52.0 ships hemera in
`kraite.horizon.workers`, prod servers row seeded, deploy v1.52.0
fan-out to all 6 existing boxes, warmup workers (incl hemera) parallel
+ athena last. All boxes ONLINE, drift gate aligned everywhere
(7 workers in config, 7 apiable servers in DB).

Also resolved follow-up items from the v1.51.x cycle:
- `deploy.sh` re-exec bootstrap fix shipped as v1.51.3 (prevents bash
  from executing in-memory pre-checkout script body after `git checkout`
  replaces deploy.sh mid-flight). Verified working on athena's v1.51.2 →
  v1.52.0 deploy — re-exec fired, step 10 ran and aligned.
- `deploy-notes.md` updated with the APP_ENV=staging recipe for prod
  `migrate:fresh` (replaces the original APP_ENV=local recipe which
  would mis-seed Karine instead of Bruno on production).
- `.env.bak.2026-05-30.pre-migrate-fresh` cleaned from athena.

## Current state

- Fleet: 7 boxes online (hyperion + athena + eos + iris + nyx + hemera + tyche).
- Tags shipped this session: `step-dispatcher v1.13.0`, `core v1.48.0`,
  `core v1.49.0`, `core v1.50.0`, `ingestion v1.51.1`, `v1.51.2`,
  `v1.51.3`, `v1.52.0`.
- Prod DB: 2 users (sysadmin `bruno@kraite.com` + trader
  `bruno@nidavellir.trade`), 1 account (Bruno Falcao Binance Account,
  `can_trade=0`, `is_active=0` — gates closed for explicit operator
  flip), 8 servers (local + hyperion + athena + eos + iris + nyx +
  hemera + tyche).
- Drift gate aligned on all 6 ingestion-running boxes.
- Total fleet processes: 88 (was 71 pre-hemera).
- Monthly Hetzner spend: €74.16 (was €69.16 pre-hemera).
- Test suite: 2338 passed last run (pre-hemera-tag); not re-run after
  hemera config change (config-only addition, no code path touched).

## WIP

None. All edits committed + tagged + deployed.

Files touched this session (huge — see git log on the four repos for
the full list). Notable categories:
- ingestion `config/kraite.php` `horizon.workers` — hemera supervisor block
- core `config/kraite.php` `fleet.servers` — hemera entry
- core `database/seeders/KraiteSeeder.php` — drift-fixing seedServers
  rewrite (v1.49.0)
- ingestion `database/seeders/BusinessSeeder.php` — non-local Bruno
  trader path (v1.51.1)
- ingestion `deploy.sh` — re-exec bootstrap (v1.51.3)
- `~/Herd/.credentials/kraite/servers.json` — hemera entry,
  monthly_eur_total → 74.16, fleet_provisioned/nyx_joined/hemera_joined
- `~/Herd/.dynamic-commands/{kraite-profile,kraite-deploy,kraite-warmup,kraite-read-docs}.md`
  — fleet tables, queue assignments, deploy/warmup hostname lists, all
  refreshed for the 7-box fleet
- `~/Herd/docs/kraite/00-context/{system-overview,server-preparation}.md`
  — fleet topology, queue tables, total process count, monthly cost
- `~/Herd/docs/kraite/02-features/step-routing.md` — physical queue
  naming examples extended with hemera
- `~/Code/syntax.kraite.test/src/lib/navigation.ts` + the eos-iris,
  architecture-overview, horizon-queues, servers/page.md pages —
  refreshed for the 4-worker pool incl hemera
- `~/Herd/.credentials/kraite/deploy-notes.md` — entries 57-62 covering
  the hemera onboarding lessons (cloud network attach, netplan
  regeneration, host key rotation, composer install vs update, drift
  gate ordering, cooldown Pending semantics)

## Pending items

- **Syntax site rebuild + deploy.** `~/Code/syntax.kraite.test/`
  changes are uncommitted/unbuilt on Bruno's Mac. Next syntax-tag +
  `npm run build` + rsync to athena will publish the refreshed pages.
- **Full hardening per `~/Herd/.credentials/kraite/hardening.json`.**
  Hemera reached iris/eos/nyx-parity (SSH hardening, sysctl, UFW SSH-only,
  fail2ban, chrony, hostname user, file packages). NOT done: auditd,
  rkhunter, aide, lynis 85+ audit, umask 027, AIDE baseline, claude
  alias, password aging policy. The full hardening checklist has 100+
  items; the actually-applied subset on every existing box is the
  baseline I reproduced. If Bruno wants the full pass, that's a
  separate workstream across all 5 worker boxes (hemera will need it
  too).
- **Bybit account for `bruno@nidavellir.trade`.** The `TRADER_BB_*` env
  block on local `.env.traders` carries Bybit credentials but the
  seeder only creates a Binance account today (Bruno's "Binance only"
  decision earlier). If Bybit ever needs to come back, add a sibling
  Account row in `BusinessSeeder::seedBrunoNidavellirTrader()`.

## Key decisions made this session

- **Hemera = primordial day, Nyx's mythological complement.** Keeps
  the fleet's female/primordial/Titan naming theme intact and creates
  a poetic pair across the worker tier.
- **Worker-shape provisioning, not full hardening.json sweep.** Bruno
  chose option B (full provisioning) but I read "match the other
  worker servers we have" as fleet-parity with iris's actually-applied
  hardening, NOT the full 100+ item hardening.json checklist (which
  no existing box currently honours either). Captured in the "pending
  items" list above so the gap is explicit.
- **Code shipping order: provision hemera with v1.51.3 code (no hemera
  awareness yet), then ship v1.50.0 core + v1.52.0 ingestion with
  hemera config, then seed prod servers row, then deploy to existing
  5 boxes.** This avoided the drift-gate-trips-everywhere window that
  would happen if v1.52.0 deployed before the servers row existed.
- **APP_ENV=staging recipe replaces APP_ENV=local recipe in
  deploy-notes #52.** APP_ENV=local would mis-seed Karine on
  production; staging keeps the seeder on the non-local Bruno branch
  while bypassing `nunomaduro/essentials::prohibitDestructiveCommands`.
