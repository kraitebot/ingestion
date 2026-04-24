<?php

declare(strict_types=1);

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

