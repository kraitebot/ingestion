<?php

declare(strict_types=1);

it('routes every orchestrator child build through the locked helper', function (): void {
    $jobsDirectory = base_path('vendor/kraitebot/core/src/Jobs');
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($jobsDirectory));
    $offenders = [];

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = file_get_contents($file->getPathname());

        if (str_contains($source, '$this->step->makeItAParent()')) {
            $offenders[] = str_replace($jobsDirectory.'/', '', $file->getPathname());
        }
    }

    expect($offenders)->toBe([]);
});
