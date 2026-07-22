<?php

declare(strict_types=1);

it('keeps every external BSCS consumer behind the Bscs facade', function (): void {
    $sourceRoot = realpath(base_path('vendor/kraitebot/core/src'));
    $domainRoot = realpath(base_path('vendor/kraitebot/core/src/Support/MarketRegime'));

    expect($sourceRoot)->not->toBeFalse()
        ->and($domainRoot)->not->toBeFalse();

    $forbiddenImports = [
        'CrowdingMultiplier',
        'DirectionalBookRisk',
        'FragileMarginMultiplier',
        'RegimeCountMultiplier',
        'RegimeLeverageMultiplier',
    ];
    $violations = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceRoot));

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $path = $file->getPathname();
        if (str_starts_with($path, $domainRoot.DIRECTORY_SEPARATOR)) {
            continue;
        }

        $source = file_get_contents($path);
        if ($source === false) {
            continue;
        }

        foreach ($forbiddenImports as $class) {
            $import = 'use Kraite\\Core\\Support\\MarketRegime\\'.$class.';';

            if (str_contains($source, $import)) {
                $violations[] = str_replace($sourceRoot.DIRECTORY_SEPARATOR, '', $path).': '.$class;
            }
        }

        if (str_contains($source, 'BscsState::current()')) {
            $violations[] = str_replace($sourceRoot.DIRECTORY_SEPARATOR, '', $path).': BscsState::current';
        }
    }

    expect($violations)->toBe([]);
});
