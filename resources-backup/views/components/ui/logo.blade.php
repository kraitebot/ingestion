{{-- resources/views/components/ui/logo.blade.php --}}
{{-- Reusable Martingalian logo as a Blade UI component.
     Props:
       - class: Tailwind classes for sizing (default h-8 w-8). You can pass any size (e.g., h-16 w-16).
       - ariaLabel: Accessible label for screen readers.
       - showFrame: Toggle the rounded outer frame stroke.
       - baseline: Toggle the faint baseline under candles.
       - rounded: Corner radius for the frame (numeric).
       - glow: Toggle the subtle drop shadow around candles.
       - uid: Optional unique suffix for <defs> IDs to avoid collisions when multiple logos are on the same page.
              If not provided, a short random string is generated.
--}}

@props([
    'class'      => 'h-8 w-8',
    'ariaLabel'  => 'Martingalian',
    'showFrame'  => true,
    'baseline'   => true,
    'rounded'    => 9,
    'glow'       => true,
    'uid'        => null,
])

@php
    // Generate a stable-ish unique suffix for <defs> IDs so multiple instances don't collide.
    try {
        $suffix = $uid ?: substr(bin2hex(random_bytes(3)), 0, 6);
    } catch (\Throwable $e) {
        // Fallback if random_bytes is unavailable
        $suffix = $uid ?: substr(md5((string) microtime(true)), 0, 6);
    }

    $idRed        = "mg-red-{$suffix}";
    $idRedBody    = "mg-body-red-{$suffix}";
    $idGreen      = "mg-green-{$suffix}";
    $idGreenBody  = "mg-body-green-{$suffix}";
    $idShadow     = "mg-shadow-{$suffix}";
@endphp

<svg
    {{ $attributes->merge(['class' => $class]) }}
    viewBox="0 0 40 40"
    fill="none"
    role="img"
    aria-label="{{ $ariaLabel }}"
    preserveAspectRatio="xMidYMid meet"
    shape-rendering="crispEdges"
>
    <title>{{ $ariaLabel }}</title>

    <defs>
        {{-- Reds --}}
        <linearGradient id="{{ $idRed }}" x1="6" y1="34" x2="34" y2="6" gradientUnits="userSpaceOnUse">
            <stop stop-color="#EF4444"/>
            <stop offset="1" stop-color="#F87171"/>
        </linearGradient>
        <linearGradient id="{{ $idRedBody }}" x1="0" y1="40" x2="0" y2="0" gradientUnits="userSpaceOnUse">
            <stop offset="0" stop-color="#EF4444"/>
            <stop offset=".55" stop-color="#F36969"/>
            <stop offset="1" stop-color="#F87171"/>
        </linearGradient>

        {{-- Greens --}}
        <linearGradient id="{{ $idGreen }}" x1="6" y1="34" x2="34" y2="6" gradientUnits="userSpaceOnUse">
            <stop stop-color="#22C55E"/>
            <stop offset="1" stop-color="#86EFAC"/>
        </linearGradient>
        <linearGradient id="{{ $idGreenBody }}" x1="0" y1="40" x2="0" y2="0" gradientUnits="userSpaceOnUse">
            <stop offset="0" stop-color="#22C55E"/>
            <stop offset=".55" stop-color="#35D27A"/>
            <stop offset="1" stop-color="#86EFAC"/>
        </linearGradient>

        {{-- Tight glow to keep edges crisp --}}
        <filter id="{{ $idShadow }}" x="-35%" y="-35%" width="170%" height="170%">
            <feDropShadow dx="0" dy="0" stdDeviation="0.55" flood-color="#EF4444" flood-opacity=".55"/>
        </filter>
    </defs>

    {{-- Optional rounded frame --}}
    @if ($showFrame)
        <rect
            x="1.5" y="1.5" width="37" height="37" rx="{{ (float) $rounded }}"
            stroke="url(#{{ $idRed }})" stroke-opacity=".9" stroke-width="1"
            vector-effect="non-scaling-stroke"
        />
    @endif

    {{-- Candles group (with optional glow) --}}
    <g @if($glow) filter="url(#{{ $idShadow }})" @endif>
        {{-- Candle #1 (red) --}}
        <line x1="11.5" y1="18.5" x2="11.5" y2="28.5"
              stroke="url(#{{ $idRed }})" stroke-width="1" vector-effect="non-scaling-stroke"/>
        <rect x="10.0" y="21.5" width="3.0" height="4.0" rx="0.8"
              fill="url(#{{ $idRedBody }})"
              stroke="url(#{{ $idRed }})" stroke-width=".8" vector-effect="non-scaling-stroke"/>

        {{-- Candle #2 (red) --}}
        <line x1="18.5" y1="15.5" x2="18.5" y2="30.5"
              stroke="url(#{{ $idRed }})" stroke-width="1" vector-effect="non-scaling-stroke"/>
        <rect x="17.0" y="18.5" width="3.0" height="10.0" rx="0.8"
              fill="url(#{{ $idRedBody }})"
              stroke="url(#{{ $idRed }})" stroke-width=".8" vector-effect="non-scaling-stroke"/>

        {{-- Candle #3 (GREEN, rightmost/“latest”) --}}
        <line x1="25.5" y1="12.5" x2="25.5" y2="27.5"
              stroke="url(#{{ $idGreen }})" stroke-width="1" vector-effect="non-scaling-stroke"/>
        <rect x="24.0" y="15.5" width="3.0" height="9.0" rx="0.8"
              fill="url(#{{ $idGreenBody }})"
              stroke="url(#{{ $idGreen }})" stroke-width=".8" vector-effect="non-scaling-stroke"/>
    </g>

    {{-- Optional baseline hint --}}
    @if ($baseline)
        <path d="M7.5 29.5 H 32.5"
              stroke="url(#{{ $idRed }})" stroke-opacity=".25" stroke-width="1"
              vector-effect="non-scaling-stroke"/>
    @endif
</svg>
