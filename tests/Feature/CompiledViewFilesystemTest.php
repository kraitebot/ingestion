<?php

declare(strict_types=1);

use App\Support\Filesystem\CompiledViewFilesystem;
use Illuminate\Support\Str;
use Illuminate\View\Compilers\BladeCompiler;

test('compiles Blade views as non-executable files without changing explicit modes elsewhere', function (): void {
    $filesystem = app('files');
    $compiler = app('blade.compiler');
    $token = (string) Str::uuid();
    $sourcePath = storage_path("framework/testing/{$token}.blade.php");
    $outsidePath = storage_path("framework/testing/{$token}.php");
    $compiledPath = $compiler->getCompiledPath($sourcePath);

    expect($filesystem)->toBeInstanceOf(CompiledViewFilesystem::class)
        ->and($compiler)->toBeInstanceOf(BladeCompiler::class)
        ->and(config()->string('view.compiled'))->toBe(storage_path('framework/views/cli'))
        ->and(is_file($sourcePath))->toBeFalse()
        ->and(is_file($compiledPath))->toBeFalse()
        ->and(is_file($outsidePath))->toBeFalse();

    try {
        file_put_contents($sourcePath, 'Hello {{ "world" }}');

        $compiler->compile($sourcePath);
        $filesystem->replace($outsidePath, '<?php return true;', 0600);

        expect(is_file($compiledPath))->toBeTrue()
            ->and(fileperms($compiledPath) & 0777)->toBe(0644)
            ->and(is_file($outsidePath))->toBeTrue()
            ->and(fileperms($outsidePath) & 0777)->toBe(0600);
    } finally {
        foreach ([$sourcePath, $compiledPath, $outsidePath] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
});
