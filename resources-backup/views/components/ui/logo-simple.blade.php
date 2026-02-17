{{-- resources/views/components/ui/logo-simple.blade.php --}}
{{-- Simplified Martingalian logo optimized for small sizes (favicons)
     Props:
       - class: Tailwind classes for sizing (default h-8 w-8)
       - ariaLabel: Accessible label for screen readers
       - background: Background color (default: transparent, use 'dark' for #0c0a0b, 'red' for #ef4444)
       - uid: Optional unique suffix for <defs> IDs
--}}

@props([
    'class'      => 'h-8 w-8',
    'ariaLabel'  => 'Martingalian',
    'background' => 'transparent',
    'uid'        => null,
])

@php
    // Generate unique suffix for <defs> IDs
    try {
        $suffix = $uid ?: substr(bin2hex(random_bytes(3)), 0, 6);
    } catch (\Throwable $e) {
        $suffix = $uid ?: substr(md5((string) microtime(true)), 0, 6);
    }

    $idRed   = "mg-simple-red-{$suffix}";
    $idGreen = "mg-simple-green-{$suffix}";

    // Background color mapping
    $bgColor = match($background) {
        'dark' => '#0c0a0b',
        'red' => '#ef4444',
        default => 'none',
    };

    $bgOpacity = $background === 'transparent' ? '0' : '1';
@endphp

<svg
    {{ $attributes->merge(['class' => $class]) }}
    viewBox="0 0 40 40"
    fill="none"
    role="img"
    aria-label="{{ $ariaLabel }}"
    preserveAspectRatio="xMidYMid meet"
>
    <title>{{ $ariaLabel }}</title>

    <defs>
        {{-- Body gradients - stronger colors --}}
        <linearGradient id="{{ $idRed }}" x1="0" y1="0" x2="0" y2="1">
            <stop stop-color="#EF4444"/>
            <stop offset="1" stop-color="#DC2626"/>
        </linearGradient>

        <linearGradient id="{{ $idGreen }}" x1="0" y1="0" x2="0" y2="1">
            <stop stop-color="#22C55E"/>
            <stop offset="1" stop-color="#16A34A"/>
        </linearGradient>

        {{-- Wick gradients - subtle fade for depth --}}
        <linearGradient id="mg-wick-red-{{ $suffix }}" x1="0" y1="0" x2="0" y2="1">
            <stop stop-color="#EF4444" stop-opacity="0.85"/>
            <stop offset="0.5" stop-color="#DC2626" stop-opacity="0.7"/>
            <stop offset="1" stop-color="#B91C1C" stop-opacity="0.6"/>
        </linearGradient>

        <linearGradient id="mg-wick-green-{{ $suffix }}" x1="0" y1="0" x2="0" y2="1">
            <stop stop-color="#22C55E" stop-opacity="0.85"/>
            <stop offset="0.5" stop-color="#16A34A" stop-opacity="0.7"/>
            <stop offset="1" stop-color="#15803D" stop-opacity="0.6"/>
        </linearGradient>
    </defs>

    {{-- Optional background --}}
    @if($background !== 'transparent')
        <rect x="0" y="0" width="40" height="40" rx="8" fill="{{ $bgColor }}" fill-opacity="{{ $bgOpacity }}"/>
    @endif

    {{-- Simplified candles with designed wicks - showing upward trend --}}
    <g>
        {{-- Candle #1 (red) - starting position --}}
        {{-- Upper wick (resistance test) --}}
        <line x1="11.5" y1="19" x2="11.5" y2="22"
              stroke="url(#mg-wick-red-{{ $suffix }})" stroke-width="1.5"
              stroke-linecap="round"/>
        {{-- Body --}}
        <rect x="9" y="22" width="5" height="8" rx="1.5"
              fill="url(#{{ $idRed }})" opacity="0.95"
              stroke="url(#{{ $idRed }})" stroke-width="0.5" stroke-opacity="0.3"/>
        {{-- Lower wick (support test) --}}
        <line x1="11.5" y1="30" x2="11.5" y2="32"
              stroke="url(#mg-wick-red-{{ $suffix }})" stroke-width="1.5"
              stroke-linecap="round"/>

        {{-- Candle #2 (red) - decline continues --}}
        {{-- Upper wick --}}
        <line x1="19" y1="16" x2="19" y2="19"
              stroke="url(#mg-wick-red-{{ $suffix }})" stroke-width="1.5"
              stroke-linecap="round"/>
        {{-- Body --}}
        <rect x="16.5" y="19" width="5" height="12" rx="1.5"
              fill="url(#{{ $idRed }})" opacity="0.95"
              stroke="url(#{{ $idRed }})" stroke-width="0.5" stroke-opacity="0.3"/>
        {{-- Lower wick --}}
        <line x1="19" y1="31" x2="19" y2="33"
              stroke="url(#mg-wick-red-{{ $suffix }})" stroke-width="1.5"
              stroke-linecap="round"/>

        {{-- Candle #3 (green) - recovery, finishing HIGHER (selling at better price) --}}
        {{-- Upper wick (reaching for profit) --}}
        <line x1="26.5" y1="10" x2="26.5" y2="12"
              stroke="url(#mg-wick-green-{{ $suffix }})" stroke-width="1.5"
              stroke-linecap="round"/>
        {{-- Body --}}
        <rect x="24" y="12" width="5" height="10" rx="1.5"
              fill="url(#{{ $idGreen }})" opacity="0.95"
              stroke="url(#{{ $idGreen }})" stroke-width="0.5" stroke-opacity="0.3"/>
        {{-- Lower wick (strong support) --}}
        <line x1="26.5" y1="22" x2="26.5" y2="25"
              stroke="url(#mg-wick-green-{{ $suffix }})" stroke-width="1.5"
              stroke-linecap="round"/>
    </g>
</svg>
