# WhereAreWe — 2026-05-30 (docs drift sweep)

## Date

2026-05-30

## Session summary

`/do update-docs` run with explicit instruction to include
`syntax.kraite.test`. Surveyed recent commits across ingestion,
`kraitebot/core`, `brunocfalcao/step-dispatcher`, `brunocfalcao/hub-ui`,
`admin.kraite.test`, `console.kraite.test`, `kraite.test`, and the
Next.js docs site at `~/Code/syntax.kraite.test`. Cross-checked every
spec under `~/Herd/docs/kraite/` against live config and code. Patched
the concrete drift; left snapshot-style docs flagged as snapshots.

## Current state

- Test suite status: not run this session (docs-only changes).
- PHPStan / static analysis: not run this session.
- Active branch on ingestion: `master`, clean working tree apart from
  the `composer.lock` refresh from the previous push session.
- Docs tree: re-aligned with production reality as of today. The
  `03-logs/` folder referenced by the previous README is gone — the
  README index no longer points at missing files. Per-session work
  logs live in `~/Herd/.credentials/kraite/deploy-notes.md` instead.

## WIP

None. All edits in this session are committed to disk (the docs tree
is not a git repo on this machine; the syntax.kraite.test repo at
`~/Code/syntax.kraite.test` has uncommitted edits awaiting a tag /
build / deploy of the public site).

Files touched this session:

- `~/Herd/docs/kraite/README.md` — index rewrite (dropped ghost
  `03-logs/` references, added pointers to the canonical specs that
  actually exist, retired-folder note pointing at `deploy-notes.md`).
- `~/Herd/docs/kraite/00-context/server-preparation.md` — fleet cost
  corrected to €69.16, Horizon box count fixed (4 → 5),
  per-host pool table refreshed to current `kraite.horizon.workers`
  values (positions 5 / orders 8 / priority 3 / indicators 10 /
  cronjobs 3 / `<hostname>` 1), total processes recomputed
  (113 → 71), connection budget (162 → ~142), supervisor
  respawn count (49 → 71), PHP-FPM pool path updated to PHP 8.5.
  Topology section now explicitly calls out the deferred-transformer
  / drift-gate model that ships the topology as a single source of
  truth.
- `~/Herd/docs/kraite/02-features/notification-routing-audit.md` —
  re-stamped as a snapshot, added a top-table of canonicals shipped
  since the snapshot (`account_all_workers_blacklisted`,
  `market_regime_critical` / `_recovered` / `_compute_stale`, the
  private-beta family, `password_reset_link`), Resend swap note.
- `~/Herd/docs/kraite/04-admin/README.md` — added the private-beta
  registration completion page and the Resend-driven self-service
  password-reset page to the page index, added a Resend mail
  pointer under source-of-truth.
- `~/Code/syntax.kraite.test/src/app/docs/servers/eos-iris/page.md`
  — process counts refreshed (5 / 8 / 3 / 1).
- `~/Code/syntax.kraite.test/src/app/docs/servers/tyche/page.md` —
  process counts refreshed (10 / 3 / 1) + "10-process indicator
  pool" prose.
- `~/Code/syntax.kraite.test/src/app/docs/servers/architecture-overview/page.md`
  — tyche role description refreshed (20/5 → 10/3).
- `~/Code/syntax.kraite.test/src/app/docs/servers/page.md` — tyche
  quick-link copy refreshed.
- `~/Code/syntax.kraite.test/src/app/docs/subsystems/horizon-queues/page.md`
  — per-server worker layout table + ASCII diagram refreshed to
  current process counts.

## Pending items

- **Notification audit refresh** — the inventory tables in
  `02-features/notification-routing-audit.md` are still dated
  2026-05-04. The maintenance SQL block at the bottom of the doc
  needs to be re-run against the production DB (Mac has no DB
  access from here) so the canonical / user / engine rows fold in
  the additions I called out above. Currently surfaced as a "shipped
  since the snapshot" supplement.
- **Syntax site rebuild + deploy** — the syntax docs site changes
  on this machine sit in `~/Code/syntax.kraite.test` uncommitted.
  Bruno's next syntax-tag + build + rsync to `athena:/home/athena/
  syntax.kraite.com/` will publish them. No urgency — the public
  site shows process counts that are off by a known delta until
  rebuilt.
- **PHP version unification** — production runs PHP 8.5 (per the
  warmup script and `deploy-notes` entry 55); local composer.json
  still pins `^8.4`. Not blocking; just a note that the local env
  and prod diverge. The docs now reflect prod (PHP 8.5).

## Key decisions made this session

- **`03-logs/` ghost folder retired**, not restored. The README's
  per-log entries pointed at files that no longer exist; rather than
  recreating dated session logs, the docs tree now points at the
  durable specs (which were the things that should have been
  updated anyway) and at `deploy-notes.md` (which is the canonical
  operational log going forward).
- **Notification audit kept as a snapshot.** Adding the
  shipped-since-snapshot list at the top is more honest than
  inventing fresh rows without DB access; the maintenance SQL
  is the actual re-run path.
- **No business-logic changes.** Every patch was a doc reconciliation
  with state already in `config/kraite.php`, the seeders, the
  servers.json, and the running code. No behaviour shifted.
