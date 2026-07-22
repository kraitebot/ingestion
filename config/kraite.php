<?php

declare(strict_types=1);

return [

    'can_dispatch_steps' => env('CAN_DISPATCH_STEPS', true),

    'freeze' => [
        'marker_path' => env(
            'KRAITE_FREEZE_MARKER_PATH',
            storage_path(env('APP_ENV') === 'testing' ? 'framework/testing/kraite-frozen' : 'framework/kraite-frozen'),
        ),
        'logs_path' => env(
            'KRAITE_FREEZE_LOGS_PATH',
            storage_path(env('APP_ENV') === 'testing' ? 'framework/testing/kraite-freeze-logs' : 'logs'),
        ),
    ],

    'clone' => [
        'timeout_seconds' => (int) env('KRAITE_CLONE_TIMEOUT_SECONDS', 1800),
        'local_dump_directory' => env('KRAITE_CLONE_LOCAL_DUMP_DIRECTORY', storage_path('app/private/kraite-clone')),
        'local_password' => env('KRAITE_CLONE_LOCAL_PASSWORD', env('ADMIN_USER_PASSWORD')),
        'remote_dump_directory' => env('KRAITE_CLONE_REMOTE_DUMP_DIRECTORY', '/home/kraite/ingestion.kraite.com/storage/app/private/kraite-clone'),
        'production' => [
            'host' => env('KRAITE_CLONE_PRODUCTION_HOST', '135.181.93.226'),
            'ssh_user' => env('KRAITE_CLONE_PRODUCTION_SSH_USER', 'kraite'),
            'app_user' => env('KRAITE_CLONE_PRODUCTION_APP_USER', 'kraite'),
            'project_path' => env('KRAITE_CLONE_PRODUCTION_PROJECT_PATH', '/home/kraite/ingestion.kraite.com'),
            'identity_file' => env('KRAITE_CLONE_PRODUCTION_IDENTITY_FILE', mb_rtrim((string) env('HOME', ''), '/').'/.ssh/id_ed25519_kraite'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Backup Tier Counts (TieredStrategy)
    |--------------------------------------------------------------------------
    |
    | Corruption-resilient retention for `spatie/laravel-backup`. Each
    | tier holds the newest N distinct snapshots within its time
    | granularity. Total kept = hourly + daily + weekly. With the
    | defaults (3 / 3 / 3) the recovery surface spans roughly the
    | last few hours, last few days, and last few weeks — so an
    | undetected corruption window has to span multiple weeks before
    | it wipes the safety net.
    */
    'backup_tiers' => [
        'hourly' => (int) env('BACKUP_TIER_HOURLY', 3),
        'daily' => (int) env('BACKUP_TIER_DAILY', 0),
        'weekly' => (int) env('BACKUP_TIER_WEEKLY', 0),
    ],

    /**
     * Small safety tolerance to lower the leverage bracket in case is
     * falls inside that percentage gap, to avoid last limit order rejections.
     */
    'bracket_headroom_pct' => '0.004',

    /*
    |--------------------------------------------------------------------------
    | Performance / Feature Toggles
    |--------------------------------------------------------------------------
    |
    | slow_query_threshold_ms:     Log queries slower than this (in milliseconds).
    | can_trade:                   Global kill-switch. If false, the bot NEVER places live orders.
    | can_open_positions:          If false, existing positions can be managed/closed, but no new ones open.
    | notifications_enabled:       If false, no notifications will be sent (useful for testing).
    */
    'slow_query_threshold_ms' => env('SLOW_QUERY_THRESHOLD_MS', 45000),
    'notifications_enabled' => env('NOTIFICATIONS_ENABLED', true),

    'positions' => [
        // Hours a cleanly-closed position keeps its diagnostic breadcrumb
        // trail (model_logs, api_request_logs, api_snapshots) before the
        // janitor reclaims it. 0 = purge immediately on close. Production
        // sets 24 so the nightly DB backup captures the trail first; the
        // deferred purge is swept by `kraite:cron-purge-position-trails`.
        'trail_retention_hours' => (int) env('KRAITE_TRAIL_RETENTION_HOURS', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | BTC Correlation Analysis
    |--------------------------------------------------------------------------
    |
    | Configuration for calculating correlation between tokens and BTC.
    | Used to filter tokens based on their correlation characteristics.
    |
    | Correlation is calculated for all timeframes configured in TradeConfiguration.
    |
    | enabled: Global toggle for correlation calculation
    | window_size: Number of candles to analyze for full correlation (per timeframe)
    | rolling.window_size: Size of rolling window (subset of window_size)
    | rolling.method: Which rolling metric to store
    |   - 'recent': Most recent window only (last N candles)
    |   - 'average': Average of all sliding windows
    |   - 'weighted': Weighted average (recent windows count more)
    | rolling.step_size: Sliding window step (1 = every candle, 10 = every 10th)
    | btc_token: Token symbol to correlate against (usually BTC)
    | min_candles: Minimum candles required before calculating (0 = calculate with available data)
    */
    'correlation' => [
        'enabled' => (bool) env('CORRELATION_ENABLED', true),
        'window_size' => (int) env('CORRELATION_WINDOW_SIZE', 500),
        'rolling' => [
            'window_size' => (int) env('CORRELATION_ROLLING_WINDOW_SIZE', 100),
            'method' => env('CORRELATION_ROLLING_METHOD', 'recent'),
            'step_size' => (int) env('CORRELATION_ROLLING_STEP_SIZE', 10),
        ],
        'btc_token' => env('CORRELATION_BTC_TOKEN', 'BTC'),
        'min_candles' => (int) env('CORRELATION_MIN_CANDLES', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Token Discovery (BTC Bias-Based Selection)
    |--------------------------------------------------------------------------
    |
    | Configuration for the token discovery algorithm used to assign optimal
    | tokens to position slots. The algorithm uses BTC's current direction
    | and timeframe as the basis for scoring and selecting tokens.
    |
    | ALGORITHM OVERVIEW:
    | When BTC has a direction signal (LONG or SHORT):
    |   1. Get BTC's indicators_timeframe (e.g., "4h")
    |   2. Score tokens using: elasticity × |correlation| on that timeframe
    |   3. Optionally filter by correlation sign based on direction alignment
    |
    | CORRELATION SIGN RULES:
    | - BTC=LONG + Position=LONG   → Want POSITIVE correlation (token rises WITH BTC)
    | - BTC=LONG + Position=SHORT  → Want NEGATIVE correlation (token falls AGAINST BTC)
    | - BTC=SHORT + Position=LONG  → Want NEGATIVE correlation (token rises AGAINST BTC)
    | - BTC=SHORT + Position=SHORT → Want POSITIVE correlation (token falls WITH BTC)
    |
    | Rule: (BTC direction == position direction) → want POSITIVE correlation
    */
    'token_discovery' => [

        /*
         * Correlation type used for scoring tokens.
         * Determines which correlation metric to use when calculating token scores.
         *
         * Options:
         * - 'rolling': Rolling correlation (btc_correlation_rolling) - Recent window only, more reactive
         * - 'pearson': Pearson correlation (btc_correlation_pearson) - Full dataset linear correlation
         * - 'spearman': Spearman correlation (btc_correlation_spearman) - Rank-based, robust to outliers
         */
        'correlation_type' => env('TOKEN_DISCOVERY_CORRELATION_TYPE', 'rolling'),

        /*
         * BTC biased restriction - Controls behavior when BTC has NO direction signal.
         *
         * When BTC HAS a direction: Always uses BTC bias algorithm (BTC's timeframe + correlation sign logic)
         *
         * When BTC has NO direction:
         * - true:  STRICT - Delete all position slots, don't open any positions.
         *          Use this when you only want to trade aligned with BTC's trend.
         * - false: RELAXED - Fallback to non-BTC algorithm (iterate all timeframes,
         *          pick best elasticity × correlation score without correlation sign filtering).
         *          Use this when you want to always open positions regardless of BTC signal.
         */
        'btc_biased_restriction' => env('TOKEN_DISCOVERY_BTC_BIASED_RESTRICTION', true),

        /*
         * Require matching correlation sign - Only applies when BTC bias is active.
         *
         * When BTC direction == position direction: Want POSITIVE correlation (token moves WITH BTC)
         * When BTC direction != position direction: Want NEGATIVE correlation (token moves AGAINST BTC)
         *
         * - true:  STRICT - Only select tokens with the correct correlation sign.
         *          If no tokens match, the position slot is deleted.
         *          Use this for higher conviction trades aligned with correlation theory.
         * - false: RELAXED - Select best scoring token regardless of correlation sign.
         *          Always finds a token if any are available.
         *          Use this when you want to always fill position slots.
         */
        'require_matching_correlation_sign' => env('TOKEN_DISCOVERY_REQUIRE_MATCHING_CORRELATION_SIGN', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | BTC Elasticity Analysis
    |--------------------------------------------------------------------------
    |
    | Configuration for calculating price elasticity between tokens and BTC.
    | Elasticity measures how much a token's price movement amplifies relative to BTC.
    |
    | Elasticity is calculated for all timeframes configured in TradeConfiguration.
    |
    | enabled: Global toggle for elasticity calculation
    | window_size: Number of candles to analyze for elasticity (per timeframe)
    | btc_token: Token symbol to measure elasticity against (usually BTC)
    | min_candles: Minimum candles required (0 = calculate with available data)
    | min_movement_threshold: Minimum BTC % change to include in calculation (filter noise)
    |
    | Two separate metrics are calculated:
    | - elasticity_long: Elasticity during BTC upward movements (positive % change)
    | - elasticity_short: Elasticity during BTC downward movements (negative % change)
    */
    'elasticity' => [
        'enabled' => (bool) env('ELASTICITY_ENABLED', true),
        'window_size' => (int) env('ELASTICITY_WINDOW_SIZE', 500),
        'btc_token' => env('ELASTICITY_BTC_TOKEN', 'BTC'),
        'min_candles' => (int) env('ELASTICITY_MIN_CANDLES', 0),
        'min_movement_threshold' => (float) env('ELASTICITY_MIN_MOVEMENT_THRESHOLD', 0.0001), // 0.01% minimum movement
    ],

    /*
    |--------------------------------------------------------------------------
    | API Throttlers
    |--------------------------------------------------------------------------
    |
    | Rate limiting configuration for external API providers.
    | Each throttler enforces requests per window and minimum delays between requests.
    |
    | TAAPI.IO Throttler:
    | - Expert Plan: 75 requests per 15 seconds (300/min, 18,000/hour)
    | - Advanced Plan: 60 requests per 15 seconds (240/min, 14,400/hour)
    | - Basic Plan: 30 requests per 15 seconds (120/min, 7,200/hour)
    | - Adjust based on your plan tier
    |
    | PROFILE GUIDE (Expert Plan examples):
    | Conservative (80% capacity): 60 req/15s, 250ms delay
    | Balanced (about 90% capacity): 68 req/15s, 221ms delay
    | Aggressive (95% capacity): 71 req/15s, 200ms delay
    |
    | CoinMarketCap Throttler:
    | - Free/Hobbyist: 30 requests per minute
    | - Startup: 60 requests per minute
    | - Standard: 90 requests per minute
    | - Professional: 120 requests per minute
    | - Enterprise: 120+ requests per minute
    | - Adjust based on your plan tier
    |
    | Binance Throttler (IP-based coordination):
    | - Uses dynamic rate limiting based on response headers
    | - Three limit types: RAW_REQUESTS, REQUEST_WEIGHT, ORDERS
    | - Multiple intervals: 1S, 10S, 1M, 2M, 5M, 10M, 1H, 1D
    | - Defaults below are conservative for standard Binance Futures API
    | - Rate limits are IP-based and coordinated across workers via Cache
    */
    'throttlers' => [
        'taapi' => [
            // Maximum requests allowed per window (based on your TAAPI plan)
            'requests_per_window' => (int) env('TAAPI_THROTTLER_REQUESTS_PER_WINDOW', 68),

            // Window size in seconds (TAAPI uses 15-second windows per their docs)
            'window_seconds' => (int) env('TAAPI_THROTTLER_WINDOW_SECONDS', 15),

            // Minimum delay between consecutive requests in milliseconds
            'min_delay_between_requests_ms' => (int) env('TAAPI_THROTTLER_MIN_DELAY_MS', 221),

            // Safety threshold: stop at this percentage of limit (0.0-1.0)
            // 1.0 applies the configured 68-request profile exactly. The
            // profile itself supplies the headroom below TAAPI's 75-request
            // Expert ceiling, so no second percentage reduction is needed.
            'safety_threshold' => (float) env('TAAPI_THROTTLER_SAFETY_THRESHOLD', 1.0),

            // Bulk API construct limit (number of constructs per /bulk request)
            // This determines how many symbols are batched into a single API call.
            // TAAPI Plan Limits (constructs per bulk request):
            // - Pro: 3 constructs
            // - Expert: 20 constructs
            // - Max: 50 constructs
            // Higher values = fewer API calls but larger payload per request
            'bulk_constructs_limit' => (int) env('TAAPI_BULK_CONSTRUCTS_LIMIT', 10),
        ],

        'coinmarketcap' => [
            // Conservative: 25 requests per 60s (83% of free tier limit)
            // Provides 5-request buffer (17%) to prevent 429 rate limits
            'requests_per_window' => (int) env('COINMARKETCAP_THROTTLER_REQUESTS_PER_WINDOW', 25),
            'window_seconds' => (int) env('COINMARKETCAP_THROTTLER_WINDOW_SECONDS', 60),
            // 2.5s delay = max 24 req/min theoretical (well under 30 limit)
            'min_delay_between_requests_ms' => (int) env('COINMARKETCAP_THROTTLER_MIN_DELAY_MS', 2500),
        ],

        'binance' => [
            // Safety threshold: stop making requests when reaching this percentage of limit (0.0-1.0)
            // 0.85 = stop at 85% to leave 15% buffer before hitting the limit
            // Higher values = more aggressive (use more of available capacity)
            // Lower values = more conservative (larger safety buffer)
            'safety_threshold' => (float) env('BINANCE_THROTTLER_SAFETY_THRESHOLD', 0.85),

            // Rate limit definitions for pre-flight safety checks
            // These are checked against response header values stored in Cache
            // Type can be: REQUEST_WEIGHT or ORDERS
            // Interval format: {number}{unit} where unit is s/m/h/d (e.g., "10s", "1m")
            //
            // BINANCE FUTURES API OFFICIAL LIMITS (as of 2025):
            // - REQUEST_WEIGHT: 2400/minute, 300/10s (IP-based)
            // - ORDERS: 1200/minute, 300/10s (UID-based, per account)
            //
            // PROFILE GUIDE:
            // Conservative (50% capacity): 1200 weight/min, 150 weight/10s, 150 orders/10s
            // Balanced (85% capacity): 2040 weight/min, 255 weight/10s, 255 orders/10s
            // Aggressive (95% capacity): 2280 weight/min, 285 weight/10s, 285 orders/10s
            // Maximum (100% capacity): 2400 weight/min, 300 weight/10s, 300 orders/10s
            //
            // IMPORTANT: Adjust based on your Binance VIP tier and trading volume
            // VIP tiers may have higher limits - check Binance documentation for your tier
            'rate_limits' => [
                [
                    'type' => 'REQUEST_WEIGHT',
                    'interval' => '1m',
                    'limit' => (int) env('BINANCE_WEIGHT_LIMIT_1M', 2040), // 85% of 2400
                ],
                [
                    'type' => 'REQUEST_WEIGHT',
                    'interval' => '10s',
                    'limit' => (int) env('BINANCE_WEIGHT_LIMIT_10S', 255), // 85% of 300
                ],
                [
                    'type' => 'ORDERS',
                    'interval' => '1m',
                    'limit' => (int) env('BINANCE_ORDERS_LIMIT_1M', 1020), // 85% of 1200
                ],
                [
                    'type' => 'ORDERS',
                    'interval' => '10s',
                    'limit' => (int) env('BINANCE_ORDERS_LIMIT_10S', 255), // 85% of 300
                ],
            ],

            // Advanced settings
            'advanced' => [
                // Track weight-based metrics (instead of just request count)
                // When true, throttler considers endpoint weights (e.g., /fapi/v2/positionRisk = 5 weight)
                'track_weight' => (bool) env('BINANCE_TRACK_WEIGHT', true),

                // Track order count per account (UID-based limits)
                // When true, throttler monitors per-account order placement limits
                'track_orders_per_account' => (bool) env('BINANCE_TRACK_ORDERS_PER_ACCOUNT', false),

                // Automatically fetch current rate limits from /fapi/v1/exchangeInfo
                // When true, system periodically updates limits based on Binance's live values
                'auto_fetch_limits' => (bool) env('BINANCE_AUTO_FETCH_LIMITS', false),
            ],
        ],

        'bybit' => [
            // Safety threshold: stop making requests when remaining falls below this percentage (0.0-1.0)
            // 0.15 = stop when less than 15% of requests remaining to leave buffer
            // Note: Bybit uses "remaining" not "used", so LOWER threshold means MORE conservative
            // Higher values = more conservative (stop earlier when more requests remain)
            // Lower values = more aggressive (keep going until fewer requests remain)
            'safety_threshold' => (float) env('BYBIT_THROTTLER_SAFETY_THRESHOLD', 0.15),

            // Rate limit configuration (fallback when headers unavailable)
            // BYBIT API OFFICIAL LIMITS (as of 2025):
            // - HTTP Level: 600 requests per 5 seconds per IP (hard limit, triggers 403 ban)
            // - API Level: Varies by endpoint and account tier
            //
            // PROFILE GUIDE:
            // Conservative (83% capacity): 500 req/5s
            // Balanced (92% capacity): 550 req/5s
            // Aggressive (97% capacity): 580 req/5s
            'requests_per_window' => (int) env('BYBIT_THROTTLER_REQUESTS_PER_WINDOW', 550), // 92% of 600
            'window_seconds' => (int) env('BYBIT_THROTTLER_WINDOW_SECONDS', 5),
        ],

        'kucoin' => [
            // Minimum delay between requests in milliseconds
            'min_delay_ms' => (int) env('KUCOIN_THROTTLER_MIN_DELAY_MS', 0),

            // Safety threshold: stop making requests when reaching this percentage of limit (0.0-1.0)
            // 0.85 = stop at 85% to leave 15% buffer before hitting the limit
            'safety_threshold' => (float) env('KUCOIN_THROTTLER_SAFETY_THRESHOLD', 0.85),

            // Rate limit configuration
            // KUCOIN FUTURES API OFFICIAL LIMITS:
            // - Public endpoints: 30 requests per 3 seconds per IP
            // - Private endpoints: 75 requests per 3 seconds per IP
            // - We use the more conservative public limit
            //
            // PROFILE GUIDE (using window-based limiting, no per-request delay):
            // Conservative (80% capacity): 24 req/3s
            // Balanced (85% capacity): 25 req/3s
            // Aggressive (95% capacity): 28 req/3s
            'requests_per_window' => (int) env('KUCOIN_THROTTLER_REQUESTS_PER_WINDOW', 25), // 85% of 30
            'window_seconds' => (int) env('KUCOIN_THROTTLER_WINDOW_SECONDS', 3),
        ],

        'bitget' => [
            // Optional floor above the endpoint-derived delay.
            'min_delay_ms' => (int) env('BITGET_THROTTLER_MIN_DELAY_MS', 0),
            'safety_threshold' => (float) env('BITGET_THROTTLER_SAFETY_THRESHOLD', 0.85),
            'aggregate_requests_per_minute' => (int) env('BITGET_AGGREGATE_REQUESTS_PER_MINUTE', 6000),
            'public_requests_per_second' => (int) env('BITGET_PUBLIC_REQUESTS_PER_SECOND', 20),
            'position_tier_requests_per_second' => (int) env('BITGET_POSITION_TIER_REQUESTS_PER_SECOND', 10),
            'private_requests_per_second' => (int) env('BITGET_PRIVATE_REQUESTS_PER_SECOND', 10),
            'position_requests_per_second' => (int) env('BITGET_POSITION_REQUESTS_PER_SECOND', 5),
            'position_history_requests_per_second' => (int) env('BITGET_POSITION_HISTORY_REQUESTS_PER_SECOND', 20),
            'flash_close_requests_per_second' => (int) env('BITGET_FLASH_CLOSE_REQUESTS_PER_SECOND', 1),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Live API Credentials (NOT per-account exchange creds)
    |--------------------------------------------------------------------------
    |
    | api.credentials.*: Service-level API keys used by background jobs (market data,
    | indicators, metadata, notifications, etc.). These are NOT your trading sub-accounts.
    */
    'api' => [
        'url' => [
            'binance' => [
                'rest' => 'https://fapi.binance.com',
                'stream' => 'wss://fstream.binance.com',
            ],

            'bybit' => [
                'rest' => 'https://api.bybit.com',
                'stream' => 'wss://stream.bybit.com',
            ],

            'kucoin' => [
                'rest' => 'https://api-futures.kucoin.com',
            ],

            'bitget' => [
                'rest' => 'https://api.bitget.com',
                'stream' => 'wss://ws.bitget.com/v2/ws/public',
            ],

            'alternativeme' => [
                'rest' => 'https://api.alternative.me',
            ],

            'coinmarketcap' => [
                'rest' => 'https://pro-api.coinmarketcap.com',
            ],

            'taapi' => [
                'rest' => 'https://api.taapi.io',
            ],
        ],

        // Pushover configuration for notifications
        'pushover' => [
            // Delivery groups
            // Each group maps to a Pushover delivery group with its configuration
            // Priority levels: -2 (lowest), -1 (low), 0 (normal), 1 (high), 2 (emergency)
            'delivery_groups' => [
                'exceptions' => [
                    'group_key' => env('PUSHOVER_DG_EXCEPTIONS'),
                    'priority' => 2, // Emergency priority with siren sound
                ],
                'default' => [
                    'group_key' => env('PUSHOVER_DG_DEFAULT'),
                    'priority' => 0, // Normal priority
                ],
                'indicators' => [
                    'group_key' => env('PUSHOVER_DG_INDICATORS'),
                    'priority' => 0, // Normal priority
                ],
            ],
        ],

        // Webhook URLs for notification delivery confirmations
        // Used by external gateways to confirm delivery/bounces/opens
        'webhooks' => [
            // Zeptomail webhook URL (receives: hard bounce, soft bounce, open events)
            // Configure in Zeptomail dashboard: Settings > Webhooks
            // Example: https://your-domain.com/api/webhooks/zeptomail/events
            'zeptomail' => env('ZEPTOMAIL_WEBHOOK_URL'),

            // Zeptomail webhook secret for signature verification
            // Get this from Zeptomail dashboard: Settings > Webhooks > Secret Key
            'zeptomail_secret' => env('ZEPTOMAIL_WEBHOOK_SECRET'),

            // Pushover callback URL (for emergency-priority receipt acknowledgment)
            // Used as 'callback' parameter when sending emergency notifications
            // Example: https://your-domain.com/api/webhooks/pushover/receipt
            'pushover' => env('PUSHOVER_WEBHOOK_URL'),
        ],

        'credentials' => [

            // Live Binance keys (service-level; NOT user account keys used to place orders).
            'binance' => [
                'api_key' => env('BINANCE_API_KEY'),
                'api_secret' => env('BINANCE_API_SECRET'),
            ],

            // Live Bybit keys (service-level; NOT user account keys used to place orders).
            'bybit' => [
                'api_key' => env('BYBIT_API_KEY'),
                'api_secret' => env('BYBIT_API_SECRET'),
            ],

            // Live KuCoin keys (service-level; NOT user account keys used to place orders).
            'kucoin' => [
                'api_key' => env('KUCOIN_API_KEY'),
                'api_secret' => env('KUCOIN_API_SECRET'),
                'passphrase' => env('KUCOIN_PASSPHRASE'),
            ],

            // Live BitGet keys (service-level; NOT user account keys used to place orders).
            'bitget' => [
                'api_key' => env('BITGET_API_KEY'),
                'api_secret' => env('BITGET_API_SECRET'),
                'passphrase' => env('BITGET_PASSPHRASE'),
            ],

            // TAAPI indicator provider.
            'taapi' => [
                'secret' => env('TAAPI_SECRET'),
            ],

            // CoinMarketCap metadata provider.
            'coinmarketcap' => [
                'api_key' => env('COINMARKETCAP_API_KEY'),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Dispatch-Time Queue Routing
    |--------------------------------------------------------------------------
    |
    | Single source of truth for queue topology. Both the
    | StepRouter (which picks the physical per-hostname queue at dispatch
    | time) AND `config/horizon.php` (which spawns the supervisors that
    | consume those queues) read from this config block. Keeping them in
    | one place eliminates the drift risk where horizon.php would
    | subscribe to a queue name that the router never picks (or vice
    | versa) — drift would silently wedge steps in queues with no
    | consumer.
    |
    | horizon.workers — per-hostname supervisor configuration. Each
    | hostname's block lists the LOGICAL queue names it serves (positions,
    | orders, cronjobs, …) with per-queue overrides (currently just
    | `processes`). The horizon transformer composes the PHYSICAL queue
    | name as `{hostname}-{logical}` (e.g. kraite-positions) on dispatch
    | and supervisor spawn. Special case: when the logical name already
    | equals the hostname (the direct `kraite` queue), no prefix
    | is added.
    |
    | horizon.defaults — supervisor options applied to every block before
    | the per-queue override is merged. Tunable per-environment if needed
    | by env-suffixing the keys (e.g. for a slower local box).
    |
    | queue_subscriptions — derived view used by StepRouter. For each
    | logical queue, the list of hostnames that can serve it. Kept as an
    | explicit map (rather than computed from horizon.workers at runtime)
    | so the StepRouter doesn't need to walk the full worker block on
    | every dispatch. The `kraite:verify-horizon-topology` command
    | asserts the two views stay aligned.
    */
    'horizon' => [
        'defaults' => [
            'connection' => 'redis',
            'timeout' => 0,
            'sleep' => 1,
            'tries' => 5,
            'backoff' => 10,
            'memory' => 256,
        ],
        'workers' => [
            // Local Mac dev box. Subscribes to every logical category so
            // a single developer can exercise the full pipeline without
            // spinning up multiple Horizon supervisors. Hostname is the
            // value gethostname() returns on the dev box, lower-cased
            // and stripped of dashes (matches StepObserver's hostname
            // queue allowlist normalisation).
            //
            // ENV-SCOPED: present only when APP_ENV=local. In production there
            // is no `local` box, so the deploy-time fleet-topology drift gate
            // (`verify-fleet-topology --fail-on-drift`) must NOT expect a
            // `local` row in the prod `servers` table. Spread-merged so the
            // production workers map below is left exactly as-is.
            ...(env('APP_ENV', 'production') === 'local' ? [
                'local' => [
                    'positions' => ['processes' => 2],
                    'orders' => ['processes' => 5],
                    'priority' => ['processes' => 2],
                    'cronjobs' => ['processes' => 2],
                    'indicators' => ['processes' => 5],
                    'user-data-stream' => ['processes' => 1],
                    'local' => ['processes' => 1],
                ],
            ] : []),
            // Single-user production host. One Horizon instance consumes
            // every queue while leaving capacity for MySQL, Redis, PHP-FPM,
            // the scheduler, dispatcher, and Binance streams on 8 GB RAM.
            'kraite' => [
                'positions' => ['processes' => 2],
                'orders' => ['processes' => 3],
                'priority' => ['processes' => 1],
                'cronjobs' => ['processes' => 2],
                'indicators' => ['processes' => 3],
                'user-data-stream' => ['processes' => 1],
                'web' => ['processes' => 1],
                'kraite' => ['processes' => 1],
            ],
        ],
    ],

];
