<?php

declare(strict_types=1);

namespace App\Support\Filesystem;

use Illuminate\Filesystem\Filesystem;

/**
 * Keeps the compiled Blade cache readable but non-executable for every runtime
 * identity. Laravel's default replace mode follows the process umask, which
 * otherwise produces executable compiled PHP files in this deployment.
 */
final class CompiledViewFilesystem extends Filesystem
{
    public function replace($path, $content, $mode = null): void
    {
        if ($mode === null && $this->isCompiledViewPath((string) $path)) {
            $mode = 0644;
        }

        parent::replace($path, $content, $mode);
    }

    private function isCompiledViewPath(string $path): bool
    {
        $compiledDirectory = mb_rtrim(config()->string('view.compiled'), DIRECTORY_SEPARATOR);

        return $compiledDirectory !== ''
            && str_starts_with($path, $compiledDirectory.DIRECTORY_SEPARATOR);
    }
}
