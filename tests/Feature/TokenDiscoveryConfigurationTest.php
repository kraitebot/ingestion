<?php

declare(strict_types=1);

use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

it('loads token discovery safety thresholds from environment variables', function (): void {
    $php = (new PhpExecutableFinder)->find();

    expect($php)->toBeString();

    $process = new Process(
        [
            $php,
            '-r',
            <<<'PHP'
                require 'vendor/autoload.php';
                $app = require 'bootstrap/app.php';
                $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

                echo json_encode([
                    'sr_safe_zone' => config('kraite.token_discovery.sr_safe_zone'),
                    'mark_price_max_age_seconds' => config('kraite.token_discovery.mark_price_max_age_seconds'),
                ], JSON_THROW_ON_ERROR);
                PHP,
        ],
        base_path(),
        [
            'APP_ENV' => 'testing',
            'TOKEN_DISCOVERY_SR_SAFE_ZONE' => '0.37',
            'TOKEN_DISCOVERY_MARK_PRICE_MAX_AGE_SECONDS' => '99',
        ],
    );

    $process->mustRun();

    expect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toBe([
        'sr_safe_zone' => 0.37,
        'mark_price_max_age_seconds' => 99,
    ]);
});
