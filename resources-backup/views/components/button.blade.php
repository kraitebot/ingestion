{{-- resources/views/components/button.blade.php --}}
{{--
    Alpine.js-aware button component with variant support

    Usage:
    <x-button variant="primary" @click="submit" :disabled="loading">
        <x-feathericon-save class="h-4 w-4" x-show="!loading"/>
        <span x-text="loading ? 'Saving...' : 'Save'"></span>
    </x-button>
--}}

@props([
    'variant' => 'primary',
    'size' => 'md',
])

@php
    // Get theme-based colors using the helper
    $primaryColors = theme_map_color(theme('primary.base'));
    $secondaryColors = theme_map_color(theme('secondary.base'));
    $successColors = theme_map_color(theme('success.base'));
    $errorColors = theme_map_color(theme('error.base'));

    // Build variant classes from theme config
    $variantClasses = [
        'primary' => $primaryColors['bg'] . ' ' . $primaryColors['hover'] . ' ' . $primaryColors['border'] . ' disabled:' . $primaryColors['hover'],
        'secondary' => $secondaryColors['bg'] . ' ' . $secondaryColors['hover'] . ' ' . $secondaryColors['border'] . ' disabled:' . $secondaryColors['hover'],
        'success' => $successColors['bg'] . ' ' . $successColors['hover'] . ' ' . $successColors['border'] . ' disabled:' . $successColors['hover'],
        'error' => $errorColors['bg'] . ' ' . $errorColors['hover'] . ' ' . $errorColors['border'] . ' disabled:' . $errorColors['hover'],
    ];

    // Size classes
    $sizeClasses = [
        'sm' => 'px-4 py-2 text-sm',
        'md' => 'px-6 py-3 text-sm',
    ];

    // Build final classes
    $classes = trim(
        'inline-flex items-center gap-2 rounded-lg border-2 font-medium text-white shadow-sm transition-colors ' .
        ($variantClasses[$variant] ?? $variantClasses['primary']) . ' ' .
        ($sizeClasses[$size] ?? $sizeClasses['md'])
    );
@endphp

<button
    {{ $attributes->merge(['class' => $classes, 'type' => 'button']) }}
>
    {{ $slot }}
</button>
