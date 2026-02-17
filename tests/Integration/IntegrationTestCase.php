<?php

declare(strict_types=1);

namespace Tests\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Http;
use NotificationChannels\Pushover\Pushover;
use Tests\TestCase;

/**
 * Base test case for integration tests.
 *
 * Integration tests validate real component interactions without external dependencies.
 * Unlike unit tests with fakes, these tests ensure components actually work together.
 */
abstract class IntegrationTestCase extends TestCase
{
    /**
     * Captured log messages during test execution.
     *
     * @var array<int, array{level: string, message: string, context: array}>
     */
    protected array $logMessages = [];

    /**
     * Mock handler for API calls (Binance/Bybit).
     */
    protected ?MockHandler $apiMockHandler = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Prevent real Pushover notifications via three paths:

        // 1. Set a fake Pushover token to prevent any real API calls
        //    The PushoverServiceProvider creates a new Guzzle client with config('services.pushover.token')
        //    Using a fake token ensures notifications won't reach real Pushover API
        config(['services.pushover.token' => 'fake_token_for_testing_12345']);

        // 2. Mock Pushover with a mocked Guzzle client
        //    The Pushover package uses Guzzle directly, so we need to mock it
        //    Queue multiple responses since tests may send multiple notifications
        $responses = array_fill(0, 100, new Response(200, [], json_encode(['status' => 1])));
        $mockHandler = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mockHandler);
        $mockGuzzleClient = new GuzzleClient(['handler' => $handlerStack]);

        $mockPushover = new Pushover($mockGuzzleClient, 'fake_token_for_testing_12345');

        // Override the contextual binding from PushoverServiceProvider
        // This ensures our mocked Pushover is used instead of creating a new one
        $this->app->when(\NotificationChannels\Pushover\PushoverChannel::class)
            ->needs(Pushover::class)
            ->give(static function () use ($mockPushover) {
                return $mockPushover;
            });

        // 3. Fake Laravel Http facade (used by NotificationService::sendDirectToPushover)
        //    This prevents direct API calls when sending to virtual admin
        Http::fake([
            'api.pushover.net/*' => Http::response(['status' => 1], 200),
        ]);

        // Note: We do NOT use Notification::fake() because we want emails to actually
        // render to the log driver for integration testing

        // Use log driver for email integration tests (override phpunit.xml array driver)
        config(['mail.default' => 'log']);

        // Clear any existing log file for integration tests
        $logPath = storage_path('logs/laravel.log');
        if (file_exists($logPath)) {
            file_put_contents($logPath, '');
        }

        // NOTE: This base class is deprecated in favor of using Pest.php configuration
        // with factory-based data creation. See tests/Integration/Notifications/ for examples.
        //
        // New integration tests should:
        // 1. Use Pest.php configuration (no explicit uses() needed)
        // 2. Create data using factories (NotificationFactory, ApiSystemFactory, etc.)
        // 3. Leverage RefreshDatabase transactions for speed
    }

    protected function tearDown(): void
    {
        $this->logMessages = [];

        parent::tearDown();
    }

    /**
     * Assert that a step was created with specific job class.
     */
    protected function assertStepCreated(string $jobClass, ?int $index = null, ?string $group = null): void
    {
        $query = \StepDispatcher\Models\Step::where('class', $jobClass);

        if ($index !== null) {
            $query->where('index', $index);
        }

        if ($group !== null) {
            $query->where('group', $group);
        }

        $this->assertTrue($query->exists(), "Step not found: {$jobClass}".($index ? " (index: {$index})" : '').($group ? " (group: {$group})" : ''));
    }

    /**
     * Assert the number of steps created.
     */
    protected function assertStepsCount(int $expected, ?string $group = null): void
    {
        $query = \StepDispatcher\Models\Step::query();

        if ($group !== null) {
            $query->where('group', $group);
        }

        $actual = $query->count();

        $this->assertEquals($expected, $actual, "Expected {$expected} steps".($group ? " in group '{$group}'" : '').", found {$actual}");
    }

    /**
     * Assert that an account is disabled with specific reason.
     */
    protected function assertAccountDisabled(\Kraite\Core\Models\Account $account, string $reason): void
    {
        $account = $account->fresh();

        $this->assertFalse($account->can_trade, 'Account should be disabled (can_trade=false)');
        $this->assertEquals($reason, $account->disabled_reason, "Expected disabled_reason: {$reason}");
        $this->assertNotNull($account->disabled_at, 'disabled_at should not be null');
    }

    /**
     * Assert that a position was created for account and symbol.
     */
    protected function assertPositionCreated(\Kraite\Core\Models\Account $account, string $symbol): void
    {
        $position = \Kraite\Core\Models\Position::where('account_id', $account->id)
            ->whereHas('exchangeSymbol', static function ($query) use ($symbol) {
                $query->where('symbol', $symbol);
            })
            ->first();

        $this->assertNotNull($position, "Position not found for account {$account->id} and symbol {$symbol}");
    }

    /**
     * Assert that an order was placed for position.
     */
    protected function assertOrderPlaced(\Kraite\Core\Models\Position $position, string $type): void
    {
        $order = \Kraite\Core\Models\Order::where('position_id', $position->id)
            ->where('type', $type)
            ->first();

        $this->assertNotNull($order, "Order of type '{$type}' not found for position {$position->id}");
    }

    /**
     * Assert that an API request was logged.
     */
    protected function assertApiRequestLogged(\Kraite\Core\Models\Account $account, int $httpCode): void
    {
        $exists = \Kraite\Core\Models\ApiRequestLog::where('account_id', $account->id)
            ->where('http_response_code', $httpCode)
            ->exists();

        $this->assertTrue($exists, "ApiRequestLog not found for account {$account->id} with HTTP code {$httpCode}");
    }

    /**
     * Mock API responses for Binance/Bybit calls.
     *
     * Usage:
     * $this->mockApiResponses([
     *     new Response(200, [], json_encode(['orderId' => 123])),
     *     new Response(200, [], json_encode(['status' => 'FILLED'])),
     * ]);
     */
    protected function mockApiResponses(array $responses): void
    {
        $this->apiMockHandler = new MockHandler($responses);
        $handlerStack = HandlerStack::create($this->apiMockHandler);
        $mockGuzzleClient = new GuzzleClient(['handler' => $handlerStack]);

        // Bind mocked Guzzle client for all API clients
        $this->app->bind(Client::class, static function () use ($mockGuzzleClient) {
            return $mockGuzzleClient;
        });
    }
}
