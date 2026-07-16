<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Kraite\Core\Support\FreezeMode;

afterEach(function (): void {
    File::delete(storage_path('framework/testing/kraite-frozen'));
});

it('isolates the test marker from the active local runtime marker', function (): void {
    File::delete(storage_path('framework/testing/kraite-frozen'));

    expect(FreezeMode::markerPath())
        ->toBe(storage_path('framework/testing/kraite-frozen'))
        ->not->toBe(storage_path('framework/kraite-frozen'))
        ->and(FreezeMode::isActive())
        ->toBeFalse();
});
