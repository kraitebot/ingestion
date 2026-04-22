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
 * row keyed by OS hostname, falling back to a cached external lookup.
 */
uses()->group('unit', 'kraite', 'server-ip');

beforeEach(function () {
    Cache::forget(Kraite::IP_CACHE_KEY);
});

it('returns the ip_address from the servers row matching the OS hostname', function () {
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

it('falls back to the external resolver when no servers row matches and caches the result', function () {
    Http::fake([
        'api.ipify.org*' => Http::response('198.51.100.7', 200),
    ]);

    expect(Kraite::ip())->toBe('198.51.100.7');
    expect(Cache::get(Kraite::IP_CACHE_KEY))->toBe('198.51.100.7');

    // Second call must hit the cache — no additional HTTP request goes out.
    expect(Kraite::ip())->toBe('198.51.100.7');
    Http::assertSentCount(1);
});

it('throws when the external resolver returns a loopback or private ip', function () {
    Http::fake([
        'api.ipify.org*' => Http::response('127.0.1.1', 200),
    ]);

    Kraite::ip();
})->throws(RuntimeException::class, 'Unable to resolve the server public IP');

it('reads from the cached fallback without hitting the external resolver when primed', function () {
    Cache::put(Kraite::IP_CACHE_KEY, '203.0.113.99', Kraite::IP_CACHE_TTL_SECONDS);

    // Stray request detection would blow up if the resolver was called.
    Http::preventStrayRequests();

    expect(Kraite::ip())->toBe('203.0.113.99');
});
