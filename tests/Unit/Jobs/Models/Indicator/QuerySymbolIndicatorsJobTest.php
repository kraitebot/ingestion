<?php

declare(strict_types=1);

use Kraite\Core\Jobs\Models\Indicator\QuerySymbolIndicatorsJob;

it('takes its retry budget from indicator configuration', function (): void {
    expect(config('kraite.indicators.query_retries'))->toBe(150);

    $defaultJob = new QuerySymbolIndicatorsJob(101, '1h');

    config()->set('kraite.indicators.query_retries', 37);

    $configuredJob = new QuerySymbolIndicatorsJob(202, '4h');

    expect($defaultJob->retries)->toBe(150)
        ->and($configuredJob->retries)->toBe(37);
});
