<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\Server;

/**
 * Regression suite for `Kraite::ip()` — historically the fallback leaked
 * `127.0.1.1` from `gethostbyname()` on Ubuntu boxes, which poisoned every
 * IP-scoped downstream check (ForbiddenHostname, whitelist diagnostics,
 * per-IP rate-limit ledgers). The resolver now reaches for the `servers`
 * row keyed by the box's logical roster identity —
 * `kraite.fleet_metrics.hostname` when set, else the OS hostname — falling
 * back to a cached external lookup. The override exists for boxes whose OS
 * hostname is not their roster name (a dev Mac whose roster row is `local`);
 * without it every IP-scoped call there hit the live external resolver.
 */
uses()->group('unit', 'kraite', 'server-ip');

beforeEach(function (): void {
    Cache::forget(Kraite::IP_CACHE_KEY);
});

it('returns the ip_address from the servers row matching the OS hostname', function (): void {
    Server::create([
        'hostname' => gethostname(),
        'ip_address' => '203.0.113.42',
        'is_apiable' => true,
        'needs_whitelisting' => false,
        'own_queue_name' => 'default',
        'type' => 'ingestion',
    ]);

    expect(Kraite::ip())->toBe('203.0.113.42');
});

it('falls back to the external resolver when no servers row matches and caches the result', function (): void {
    Http::fake([
        'api.ipify.org*' => Http::response('198.51.100.7', 200),
    ]);

    expect(Kraite::ip())->toBe('198.51.100.7');
    expect(Cache::get(Kraite::IP_CACHE_KEY))->toBe('198.51.100.7');

    // Second call must hit the cache — no additional HTTP request goes out.
    expect(Kraite::ip())->toBe('198.51.100.7');
    Http::assertSentCount(1);
});

it('throws when the external resolver returns a loopback or private ip', function (): void {
    Http::fake([
        'api.ipify.org*' => Http::response('127.0.1.1', 200),
    ]);

    Kraite::ip();
})->throws(RuntimeException::class, 'Unable to resolve the server public IP');

it('reads from the cached fallback without hitting the external resolver when primed', function (): void {
    Cache::put(Kraite::IP_CACHE_KEY, '203.0.113.99', Kraite::IP_CACHE_TTL_SECONDS);

    // Stray request detection would blow up if the resolver was called.
    Http::preventStrayRequests();

    expect(Kraite::ip())->toBe('203.0.113.99');
});

it('prefers the fleet_metrics.hostname roster identity over the OS hostname', function (): void {
    config(['kraite.fleet_metrics.hostname' => 'local']);

    Server::create([
        'hostname' => 'local',
        'ip_address' => '127.0.0.1',
        'is_apiable' => false,
        'needs_whitelisting' => false,
        'own_queue_name' => 'local',
        'type' => 'local',
    ]);

    // A row for the OS hostname must NOT win when the override is set.
    Server::create([
        'hostname' => gethostname(),
        'ip_address' => '203.0.113.42',
        'is_apiable' => true,
        'needs_whitelisting' => false,
        'own_queue_name' => 'default',
        'type' => 'ingestion',
    ]);

    Http::preventStrayRequests();

    expect(Kraite::ip())->toBe('127.0.0.1');
});

it('falls back to the cached external lookup when the configured roster identity has no row', function (): void {
    config(['kraite.fleet_metrics.hostname' => 'ghost-host']);

    Cache::put(Kraite::IP_CACHE_KEY, '203.0.113.99', Kraite::IP_CACHE_TTL_SECONDS);
    Http::preventStrayRequests();

    expect(Kraite::ip())->toBe('203.0.113.99');
});

it('treats an empty fleet_metrics.hostname as unset and resolves via the OS hostname', function (): void {
    config(['kraite.fleet_metrics.hostname' => '']);

    Server::create([
        'hostname' => gethostname(),
        'ip_address' => '203.0.113.42',
        'is_apiable' => true,
        'needs_whitelisting' => false,
        'own_queue_name' => 'default',
        'type' => 'ingestion',
    ]);

    expect(Kraite::ip())->toBe('203.0.113.42');
});
