<?php

declare(strict_types=1);

use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use Kraite\Core\Support\Health\CallbackHealthCheck;
use Kraite\Core\Support\Health\HealthCheckRunner;
use Kraite\Core\Support\Health\Remediation\OrphanExchangeRemediator;
use Kraite\Core\Support\Health\Remediation\TradingCooldown;
use Kraite\Core\Support\Health\Remediation\WapRemediator;
use Kraite\Core\Trading\Exchange\AccountExchangeOperations;
use Kraite\Core\Trading\Exchange\ApiSystemExchangeOperations;
use Kraite\Core\Trading\Exchange\CanonicalExchangeConnection;
use Kraite\Core\Trading\Exchange\Exchange;
use Kraite\Core\Trading\Exchange\ExchangeSymbolOperations;
use Kraite\Core\Trading\Exchange\OrderExchangeOperations;
use Kraite\Core\Trading\Exchange\PositionExchangeOperations;
use Kraite\Core\Trading\Exchange\SymbolExchangeOperations;
use Kraite\Core\Trading\TokenSelection\AccountTokenSelection;
use Kraite\Core\Trading\TokenSelection\TokenSelection;

it('provides one typed exchange boundary for every API-aware aggregate', function (): void {
    expect(Exchange::forAccount(new Account))->toBeInstanceOf(AccountExchangeOperations::class)
        ->and(Exchange::forSystem(new ApiSystem))->toBeInstanceOf(ApiSystemExchangeOperations::class)
        ->and(Exchange::forExchangeSymbol(new ExchangeSymbol))->toBeInstanceOf(ExchangeSymbolOperations::class)
        ->and(Exchange::forOrder(new Order))->toBeInstanceOf(OrderExchangeOperations::class)
        ->and(Exchange::forPosition(new Position))->toBeInstanceOf(PositionExchangeOperations::class)
        ->and(Exchange::forSymbol(new Symbol))->toBeInstanceOf(SymbolExchangeOperations::class)
        ->and(Exchange::forCanonical('bitget'))->toBeInstanceOf(CanonicalExchangeConnection::class);
});

it('keeps exchange proxy construction inside the exchange boundary', function (): void {
    $sourceRoot = realpath(__DIR__.'/../../../packages/kraitebot/core/src');
    $boundaryRoot = realpath(__DIR__.'/../../../packages/kraitebot/core/src/Trading/Exchange');
    $proxyRoot = realpath(__DIR__.'/../../../packages/kraitebot/core/src/Support/Proxies');

    expect($sourceRoot)->not->toBeFalse()
        ->and($boundaryRoot)->not->toBeFalse()
        ->and($proxyRoot)->not->toBeFalse();

    $violations = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceRoot));

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $path = $file->getPathname();
        if (str_starts_with($path, $boundaryRoot.DIRECTORY_SEPARATOR)
            || str_starts_with($path, $proxyRoot.DIRECTORY_SEPARATOR)) {
            continue;
        }

        $source = file_get_contents($path);
        if ($source === false) {
            continue;
        }

        if (str_contains($source, 'new ApiRESTProxy') || str_contains($source, 'new ApiDataMapperProxy')) {
            $violations[] = str_replace($sourceRoot.DIRECTORY_SEPARATOR, '', $path);
        }
    }

    expect($violations)->toBe([]);
});

it('keeps model API concerns as compatibility delegates without exchange orchestration', function (): void {
    $concerns = [
        'Account',
        'ApiSystem',
        'ExchangeSymbol',
        'Order',
        'Position',
        'Symbol',
    ];

    foreach ($concerns as $concern) {
        $path = __DIR__.'/../../../packages/kraitebot/core/src/Concerns/'.$concern.'/InteractsWithApis.php';
        $source = file_get_contents($path);

        expect($source)->not->toBeFalse()
            ->and($source)->toContain('Trading\\Exchange\\Exchange')
            ->and($source)->not->toContain('new ApiProperties')
            ->and($source)->not->toContain('prepareQuery')
            ->and($source)->not->toContain('resolveQuery');
    }
});

it('provides one account-scoped token-selection boundary', function (): void {
    expect(TokenSelection::forAccount(new Account))->toBeInstanceOf(AccountTokenSelection::class);
});

it('keeps the account token concern as a compatibility delegate', function (): void {
    $path = __DIR__.'/../../../packages/kraitebot/core/src/Concerns/Account/HasTokenDiscovery.php';
    $source = file_get_contents($path);

    expect($source)->not->toBeFalse()
        ->and($source)->toContain('Trading\\TokenSelection\\TokenSelection')
        ->and($source)->not->toContain('Cache::')
        ->and($source)->not->toContain('ExchangeSymbol::query')
        ->and($source)->not->toContain('Position::query');
});

it('resolves the shared health check runner from the container', function (): void {
    expect(app(HealthCheckRunner::class))->toBeInstanceOf(HealthCheckRunner::class);
});

it('runs independent health checks and preserves their alert totals', function (): void {
    $executed = [];

    $result = app(HealthCheckRunner::class)->run([
        new CallbackHealthCheck('first', function () use (&$executed): int {
            $executed[] = 'first';

            return 2;
        }),
        new CallbackHealthCheck('second', function () use (&$executed): int {
            $executed[] = 'second';

            return 1;
        }),
    ]);

    expect($executed)->toBe(['first', 'second'])
        ->and($result->alertCount())->toBe(3)
        ->and($result->failed())->toBeFalse();
});

it('isolates a broken system health check and continues remaining checks', function (): void {
    $executed = [];

    $result = app(HealthCheckRunner::class)->run(
        checks: [
            new CallbackHealthCheck('broken', function (): int {
                throw new RuntimeException('broken check');
            }),
            new CallbackHealthCheck('healthy', function () use (&$executed): int {
                $executed[] = 'healthy';

                return 2;
            }),
        ],
        onFailure: function (string $name, Throwable $exception) use (&$executed): int {
            $executed[] = $name.':'.$exception->getMessage();

            return 1;
        },
    );

    expect($executed)->toBe(['broken:broken check', 'healthy'])
        ->and($result->alertCount())->toBe(3)
        ->and($result->failed())->toBeTrue()
        ->and($result->failures())->toHaveCount(1);
});

it('can preserve fail-fast behavior for remediation workflows', function (): void {
    app(HealthCheckRunner::class)->run(
        checks: [new CallbackHealthCheck('broken', fn (): int => throw new RuntimeException('stop'))],
        continueAfterFailure: false,
    );
})->throws(RuntimeException::class, 'stop');

it('keeps money-changing drift remediation outside the console command', function (): void {
    $path = __DIR__.'/../../../packages/kraitebot/core/src/Commands/Cronjobs/CheckDriftsCommand.php';
    $source = file_get_contents($path);

    expect(app(WapRemediator::class))->toBeInstanceOf(WapRemediator::class)
        ->and(app(TradingCooldown::class))->toBeInstanceOf(TradingCooldown::class)
        ->and($source)->not->toBeFalse()
        ->and($source)->not->toContain('Step::create(')
        ->and($source)->not->toContain("->update(['allow_opening_positions' => false])")
        ->and($source)->not->toContain('Http::asForm()');
});

it('keeps orphan exchange mutations outside the system health command', function (): void {
    $path = __DIR__.'/../../../packages/kraitebot/core/src/Commands/Cronjobs/CheckSystemHealthCommand.php';
    $source = file_get_contents($path);

    expect(app(OrphanExchangeRemediator::class))->toBeInstanceOf(OrphanExchangeRemediator::class)
        ->and($source)->not->toBeFalse()
        ->and($source)->not->toContain('new ApiProperties')
        ->and($source)->not->toContain('->withApi()');
});
