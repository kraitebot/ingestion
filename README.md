<p align="center">
  <img src="https://kraite.com/logo.png" alt="Kraite" width="200">
</p>

<h1 align="center">Kraite Ingestion</h1>

<p align="center">
  The ingestion and orchestration server for Kraite — schedules, dispatches, and coordinates the trading system.
</p>

---

## About

Kraite Ingestion is the central nervous system of the Kraite trading infrastructure. It handles:

- **Scheduler** — cron-driven periodic tasks (kline fetching, indicator computation, symbol discovery)
- **Dispatch Daemon** — persistent single-process step dispatcher replacing scheduler forks
- **WebSocket Streams** — real-time mark price feeds and multiplexed user data streams
- **Horizon Queue Management** — orchestrates job distribution across ingestion and worker servers
- **Market Regime Analysis** — BTC correlation, cascade detection, regime scoring
- **Cooldown/Warmup** — zero-downtime deploy cycle with queue draining

## Architecture

- **Athena** (ingestion) — scheduler, Redis, WebSocket streams, dispatch daemon
- **Apollo/Ares** (workers) — Horizon queue consumers for indicators, positions, orders
- **Zeus** (database) — shared MySQL
- **Hermes** (web) — kraite.com + admin.kraite.com

## Requirements

- PHP 8.4+
- Laravel 12
- MySQL 8+ (remote on Zeus)
- Redis (local on Athena)
- Supervisor

## Disclaimer

> **This software is provided for educational and informational purposes only.**
>
> Cryptocurrency trading involves substantial risk of financial loss. Algorithmic trading amplifies this risk through automated execution at speeds that prevent human intervention. Past performance does not guarantee future results.
>
> **By using, forking, or referencing this code, you acknowledge that:**
> - You may lose some or all of your invested capital
> - The authors accept no responsibility for financial losses
> - This software is not financial advice
> - You are solely responsible for your trading decisions
> - Bugs, network failures, exchange outages, or market conditions can cause unexpected losses
>
> **Do not trade with money you cannot afford to lose.**

## License

Proprietary. All rights reserved.
