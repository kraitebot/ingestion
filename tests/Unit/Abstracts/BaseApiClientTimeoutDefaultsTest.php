<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use Kraite\Core\Abstracts\BaseApiClient;

/**
 * Pins the Guzzle timeout defaults on BaseApiClient::buildClient().
 *
 * Pre-fix, the production HTTP client was constructed with no `timeout`
 * and no `connect_timeout` — Guzzle's defaults are 0/0 ("wait forever").
 * A TCP-stalled exchange connection could pin a worker indefinitely
 * inside a single Guzzle call, holding `withoutOverlapping()` cron locks
 * across the bot's entire autonomic response loop.
 *
 * This test asserts the constructed Guzzle client carries non-zero
 * timeout values so a future regression (e.g. someone bumping a default
 * to 0 to debug locally and merging the change) trips CI.
 */
it('BaseApiClient::buildClient assigns non-zero Guzzle timeout + connect_timeout', function (): void {
    $client = new class('https://example.test') extends BaseApiClient
    {
        public function exposeHttpRequest(): ?Client
        {
            return $this->httpRequest;
        }

        protected function getHeaders(): array
        {
            return [];
        }
    };

    $guzzle = $client->exposeHttpRequest();

    expect($guzzle)->toBeInstanceOf(Client::class);

    $config = $guzzle->getConfig();

    expect($config['timeout'] ?? 0)
        ->toBeInt()
        ->toBeGreaterThan(0, 'BaseApiClient must set a non-zero Guzzle `timeout` — 0 means "wait forever" and pins workers on stalled exchange sockets.');

    expect($config['connect_timeout'] ?? 0)
        ->toBeInt()
        ->toBeGreaterThan(0, 'BaseApiClient must set a non-zero Guzzle `connect_timeout` — DNS/firewall trouble must escape to the job layer rather than blocking forever on the TCP handshake.');
});
