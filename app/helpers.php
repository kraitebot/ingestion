<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

if (! function_exists('theme')) {
    /**
     * Get a theme configuration value from config/theme.php.
     *
     * @param  string  $key  Dot-notation key (e.g., 'primary.base', 'background.elevated')
     */
    function theme(string $key): string
    {
        /** @var mixed $value */
        $value = config("theme.{$key}");

        if (is_string($value)) {
            return $value;
        }

        return '';
    }
}

if (! function_exists('theme_map_color')) {
    /**
     * Map a color name to various Tailwind utility classes.
     *
     * @param  string  $colorName  Color name (e.g., 'red-500')
     * @return array<string, string>
     */
    function theme_map_color(string $colorName): array
    {
        return [
            'text' => "text-{$colorName}",
            'border' => "border-{$colorName}",
            'bg' => "bg-{$colorName}",
            'ring' => "ring-{$colorName}",
            'hover' => "hover:bg-{$colorName}",
        ];
    }
}

if (! function_exists('cleanLogsFolder')) {
    /**
     * Clear all log files and directories in storage/logs.
     */
    function cleanLogsFolder(): void
    {
        $logsPath = storage_path('logs');

        if (! File::isDirectory($logsPath)) {
            return;
        }

        // Delete all subdirectories
        /** @var array<int, string> $directories */
        $directories = File::directories($logsPath);
        foreach ($directories as $directory) {
            File::deleteDirectory($directory);
        }

        // Delete all *.log files
        /** @var array<int, string> $logFiles */
        $logFiles = File::glob($logsPath.'/*.log');
        foreach ($logFiles as $logFile) {
            File::delete($logFile);
        }
    }
}
