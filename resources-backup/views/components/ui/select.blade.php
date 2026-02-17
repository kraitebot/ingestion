{{-- resources/views/components/ui/select.blade.php --}}
@php
    /**
     * <x-ui.select>
     * - Styled select dropdown component with custom arrow
     *
     * Props:
     * - label (string, optional)
     * - help (string, optional) - alias for label
     * - disabled (bool, default false)
     * - theme ('blue'|'red', default 'blue') - color theme for focus ring
     * - All other attributes pass through to the select element
     */

    $label = $attributes->get('label') ?? $attributes->get('help');
    $disabledRaw = $attributes->get('disabled', false);
    $disabled = is_bool($disabledRaw) ? $disabledRaw : filter_var($disabledRaw, FILTER_VALIDATE_BOOLEAN);
    $theme = $attributes->get('theme', 'blue');

    // Theme colors for focus ring
    $themeClasses = match($theme) {
        'blue' => 'focus:ring-blue-400/20 focus:border-blue-400/30',
        'red' => 'focus:ring-red-400/20 focus:border-red-400/30',
        default => 'focus:ring-blue-400/20 focus:border-blue-400/30',
    };

    $bgColor = theme('background.input');
    $baseClasses = "w-full appearance-none rounded-lg border border-white/10 {$themeClasses} outline-none transition h-12 pl-4 pr-10 text-white";
    $disabledClasses = $disabled ? 'opacity-50 cursor-not-allowed' : '';

    $classes = trim("$baseClasses $disabledClasses");
    $bgStyle = "background-color: {$bgColor}";

    $passthrough = $attributes->except(['label', 'help', 'disabled', 'theme', 'class']);
@endphp

@if($label)
    <div>
        <label class="block text-sm font-medium text-white/80 mb-2">
            {{ $label }}
        </label>
        <div class="relative">
            <select
                @if($disabled) disabled @endif
                style="{{ $bgStyle }}"
                {!! $passthrough->merge(['class' => $classes]) !!}
            >
                {{ $slot }}
            </select>
            <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-4">
                <svg class="h-4 w-4 text-white/60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                </svg>
            </div>
        </div>
    </div>
@else
    <div class="relative">
        <select
            @if($disabled) disabled @endif
            style="{{ $bgStyle }}"
            {!! $passthrough->merge(['class' => $classes]) !!}
        >
            {{ $slot }}
        </select>
        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-4">
            <svg class="h-4 w-4 text-white/60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
            </svg>
        </div>
    </div>
@endif
