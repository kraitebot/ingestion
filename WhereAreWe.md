# WhereAreWe — 2026-05-24 (full fleet rebuild)

## Date
2026-05-24

## Session summary

Two-day operation (2026-05-23 → 2026-05-24):

**Why the nuke:** old 6-server fleet (zeus/athena/apollo/ares/artemis/helios + legacy hermes/current-vps)
was scrapped. Cost was running hot relative to the 50-trader scale target, and Bruno had concerns about
EU/MiCA futures access restrictions. Those concerns resolved: Bruno's KYC is Switzerland, futures access
is not affected. The rebuild was done anyway — right-sizing the fleet was overdue.

**What replaced it:** 5-server fleet in Hetzner Helsinki HEL1. All Ubuntu 26.04 LTS. All on Hetzner
private Cloud Network `kraite-net` (10.0.0.0/16). Total monthly cost: €64.16 (down from previous fleet).

Key architectural changes from the rebuild:
- **Hyperion** combines DB + Redis (removes a standalone Redis box).
- **Athena** combines ingestion + all web apps (removes separate helios web box).
- **Tyche** is new: isolated box for indicators + cronjobs (was co-located on workers before).
- **Per-hostname user** replaces the old single `waygou` pattern across the fleet.
- **Per-IP Binance weight distribution** across eos + iris + nyx (three distinct public IPs per worker box).
- Worker boxes downsized from CX33 → CX23; Horizon worker counts halved accordingly.
- **Nyx joined the fleet on 2026-05-24** as a third trading worker to extend Binance per-IP weight capacity beyond eos/iris.

Hardening run on 2026-05-24 (same day as provisioning). All 6 boxes hardened to the checklist in
`~/Herd/.credentials/kraite/hardening.json`.

---

## Fleet topology

| Hostname | Role | Public IP | Private IP | SKU | RAM | User |
|----------|------|-----------|------------|-----|-----|------|
| hyperion | Database + Redis | 135.181.93.226 | 10.0.0.2 | CCX23 | 16 GB | `hyperion` |
| athena | Ingestion + Web | 37.27.243.164 | 10.0.0.3 | CPX32 | 8 GB | `athena` |
| eos | Worker 1 | 204.168.137.153 | 10.0.0.4 | CX23 | 4 GB | `eos` |
| iris | Worker 2 | 204.168.138.83 | 10.0.0.5 | CX23 | 4 GB | `iris` |
| tyche | Worker 3 — Indicators + Cronjobs | 204.168.135.246 | 10.0.0.6 | CX23 | 4 GB | `tyche` |
| nyx | Worker 4 | 204.168.129.189 | 10.0.0.7 | CX23 | 4 GB | `nyx` |

Private network gateway: 10.0.0.1. All inter-server traffic (MySQL 3306, Redis 6379) travels private only.
UFW blocks both ports from the public internet.

### hyperion — Database + Redis

- MySQL: `kraite` DB, bound to 10.0.0.2 only. InnoDB buffer pool 11 GB (70% of 16 GB RAM, CCX23 dedicated AMD EPYC).
- Redis: co-located, bound to 10.0.0.2 + 127.0.0.1. maxmemory 3 GB, allkeys-lru, AOF + RDB for queue durability.
- Does NOT run Horizon. No exchange API calls originate here.
- Project path: `/home/hyperion/` (no app installed — DB/Redis box only).

### athena — Ingestion + Web

Supervisor processes:
- `kraite:stream-binance-user-data` — one WS daemon per Binance account
- `kraite:stream-binance-prices` — `!markPrice@arr@1s` WS feed
- `kraite:dispatch-daemon` — persistent step dispatcher (NOT a scheduled command)
- Horizon — `user-data-stream` queue only (5 workers)
- Scheduler crontab — `routes/console.php` cron family (gated `SERVER_ROLE=ingestion`)

Web stack (nginx + php8.5-fpm):
- admin.kraite.com
- kraite.com
- syntax.kraite.com

Project path: `/home/athena/ingestion.kraite.com/`

Athena dispatches jobs to worker boxes. It does NOT consume positions/orders/indicators queues itself
(beyond a 1-process connectivity-test queue for the `athena` hostname probe).

### eos — Worker 1

- Horizon queues: `positions` (5), `orders` (8), `priority` (3), `eos` (1)
- Account-to-box routing: none by design — interchangeable Horizon consumer
- Distinct public IP (204.168.137.153) → one of three Binance per-IP weight buckets
- Project path: `/home/eos/ingestion.kraite.com/`

### iris — Worker 2

- Horizon queues: `positions` (5), `orders` (8), `priority` (3), `iris` (1)
- Account-to-box routing: none by design — interchangeable Horizon consumer
- Distinct public IP (204.168.138.83) → second Binance per-IP weight bucket
- Project path: `/home/iris/ingestion.kraite.com/`

### tyche — Indicators + Cronjobs

- Horizon queues: `indicators` (10), `cronjobs` (3), `tyche` (1)
- Isolated from eos/iris/nyx: TAAPI throttler waits never starve real-time position/order processing
- Project path: `/home/tyche/ingestion.kraite.com/`

### nyx — Worker 4 (joined 2026-05-24)

- Horizon queues: `positions` (5), `orders` (8), `priority` (3), `nyx` (1) — mirror of eos/iris
- Account-to-box routing: none by design — interchangeable Horizon consumer
- Distinct public IP (204.168.129.189) → third Binance per-IP API weight bucket
- Project path: `/home/nyx/ingestion.kraite.com/`

---

## Hardening status (2026-05-24)

All 6 boxes completed:

- apt update + upgrade + autoremove
- Hostname-named sudo user (passwordless via `/etc/sudoers.d/<hostname>`)
- SSH key-only auth (root key installed, password auth disabled)
- UFW enabled: SSH open, service ports (3306/6379) private-network only on hyperion, 80/443 on athena
- fail2ban + custom jails (SSH brute force, nginx on athena)
- chrony (Cloudflare NTP pool, offset verified < 100ms — critical for Binance timestamp validation)
- sysctl hardening per role (workers: `tcp_tw_reuse=1` for outbound exchange API calls)
- `/etc/hosts` on all nodes: private hostnames mapped (`hyperion`, `athena`, `eos`, `iris`, `nyx`, `tyche`)
- etckeeper tracking `/etc/`
- auditd + rkhunter + AIDE
- logrotate (Laravel logs 14d, MySQL logs 7d, Redis logs 8w)
- Legal warning banners

Full checklist in `~/Herd/.credentials/kraite/hardening.json`.

---

## What still needs doing

Role-specific service installs not yet done on any box. Fleet is hardened but not yet running the app.

### hyperion
- [ ] Install MySQL 8.x + apply InnoDB tuning (buffer pool 11G, flush method O_DIRECT, io_capacity 2000)
- [ ] Install Redis + apply tuning (maxmemory 3GB, AOF enabled, requirepass, bind private IP only)
- [ ] Verify connectivity from all other boxes (mysql -h hyperion, redis-cli -h hyperion)

### athena
- [ ] Install nginx + php8.4-fpm + certbot (wildcard cert, Cloudflare DNS challenge)
- [ ] Install supervisor
- [ ] Install mysql-client (for mysqldump backups)
- [ ] Clone ingestion + web projects; set production composer.json; run composer install
- [ ] Configure `.env`: `SERVER_ROLE=ingestion`, `HORIZON_ENV=athena`, `REDIS_HOST=10.0.0.2`, `REDIS_DB=2`
- [ ] Deploy + seed (migrate:fresh → import positions/orders → verify → activate scheduler)
- [ ] Configure supervisor: dispatch-daemon + horizon + WS daemons
- [ ] Configure nginx vhosts + SSL for admin.kraite.com, kraite.com, syntax.kraite.com

### eos, iris, nyx, tyche (all four)
- [ ] Install php8.5 + supervisor
- [ ] Clone ingestion project; set production composer.json
- [ ] Configure `.env`: `SERVER_ROLE=worker`, `HORIZON_ENV=<hostname>`, `REDIS_HOST=10.0.0.2`, `REDIS_DB=2`
- [ ] Configure horizon.php block for each hostname's queue assignment
- [ ] Configure supervisor: horizon only

### Fleet-wide
- [ ] Verify private network connectivity between all 6 boxes
- [ ] Run `/do kraite-release` from `ingestion.kraite.test` to tag + deploy full fleet
- [ ] Run `/do kraite-health` to confirm all 6 boxes healthy post-deploy
- [ ] Activate scheduler cron on athena (ONLY after positions/orders imported + verified)

---

## Horizon queue assignment (first pass)

| Queue | athena | eos | iris | tyche |
|-------|--------|-----|------|-------|
| `user-data-stream` | 5 | — | — | — |
| `positions` | — | 5 | 5 | — |
| `orders` | — | 8 | 8 | — |
| `priority` | — | 3 | 3 | — |
| `indicators` | — | — | — | 10 |
| `cronjobs` | — | — | — | 3 |
| `<hostname>` | 1 | 1 | 1 | 1 |

Adjust as load proves out. The hostname-named queue (1 process per box) is required for the
account-onboarding connectivity-test flow.

---

## Operator-visible behaviour shifts

**Per-hostname user replaces `waygou`.**
Every box has a sudo user matching its hostname. All routine ops (artisan, composer, npm, systemctl)
run as that user. Project files are owned by `<hostname>:www-data`. Root SSH stays accessible but is
reserved for recovery only. Example on athena: `su - athena -c 'cd /home/athena/ingestion.kraite.com && php artisan ...'`.
The old `su - waygou` pattern is dead everywhere.

**All web apps deploy to athena (NOT a separate web box).**
admin.kraite.com, kraite.com, syntax.kraite.com all live on athena. There is no separate helios or
web-dedicated box. Web deploys via `/kraite-release` from their respective test folders target athena.

**Redis lives on hyperion (NOT on ingestion/athena).**
Worker `.env` files: `REDIS_HOST=10.0.0.2` (hyperion's private IP). Athena also uses `REDIS_HOST=10.0.0.2`
(not localhost anymore). `REDIS_DB=2` on all servers — this is a hard requirement; wrong DB = zero queue
visibility, silent failure.

**tyche is the new indicators + cronjobs isolation box.**
Previously indicator workers ran on apollo/ares alongside position/order workers. TAAPI throttle waits
were starving real-time processing. tyche is isolated: indicators and cronjobs only.

**Worker boxes smaller (CX23 vs previous CX33) — Horizon worker counts halved.**
First-pass numbers above. Monitor queue depth and latency; scale up counts if needed before scaling
box size.

**Binance per-IP weight distribution.**
eos / iris / nyx have three distinct public IPs (workers are interchangeable Horizon consumers with no per-account-to-box binding). This distributes
Binance API weight budget across two IP buckets, reducing per-IP rate-limit pressure at scale.

---

## Code state (last known, pre-rebuild)

- ingestion `master`: `213e7b9` (docs: dead-code sweep in v1.49.4 changelog)
- ingestion last semver tag: `v1.49.3` (fleet was on this when nuked)
- core last semver tag: `v1.46.2`
- step-dispatcher last semver tag: `v1.12.2`

No new code shipped during the rebuild session — hardening only. First post-rebuild tag will be
the next release after this snapshot.
