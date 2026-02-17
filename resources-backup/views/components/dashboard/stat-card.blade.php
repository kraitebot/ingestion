{{-- Dashboard Stat Card Component --}}
@props([
    'label' => '',
    'value' => '0',
    'isNegative' => false,
    'icon' => 'trending-up',
    'color' => 'red',
    'forceWhite' => false,
])

@php
    $colors = [
        'red' => ['border' => 'rgba(239,68,68,0.5)', 'bg' => 'rgba(239,68,68,0.5)', 'glow' => 'rgba(239,68,68,0.03)'],
        'blue' => ['border' => 'rgba(59,130,246,0.5)', 'bg' => 'rgba(59,130,246,0.5)', 'glow' => 'rgba(59,130,246,0.03)'],
        'green' => ['border' => 'rgba(34,197,94,0.5)', 'bg' => 'rgba(34,197,94,0.5)', 'glow' => 'rgba(34,197,94,0.03)'],
        'purple' => ['border' => 'rgba(168,85,247,0.5)', 'bg' => 'rgba(168,85,247,0.5)', 'glow' => 'rgba(168,85,247,0.03)'],
        'orange' => ['border' => 'rgba(249,115,22,0.5)', 'bg' => 'rgba(249,115,22,0.5)', 'glow' => 'rgba(249,115,22,0.03)'],
        'pink' => ['border' => 'rgba(236,72,153,0.5)', 'bg' => 'rgba(236,72,153,0.5)', 'glow' => 'rgba(236,72,153,0.03)'],
    ];
    $selectedColor = $colors[$color] ?? $colors['red'];
@endphp

<div class="rounded-xl relative overflow-hidden backdrop-blur-xl transition-all duration-300 hover:scale-[1.02] hover:shadow-2xl group" style="background: linear-gradient(135deg, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0.05) 100%); border: 1px solid {{ $selectedColor['border'] }}; box-shadow: 0 8px 32px rgba(0,0,0,0.3), inset 0 1px 0 rgba(255,255,255,0.1);">
    {{-- Dramatic glassmorphism overlay --}}
    <div class="absolute inset-0 rounded-xl pointer-events-none opacity-60 group-hover:opacity-100 transition-opacity duration-300" style="background: linear-gradient(135deg, {{ $selectedColor['glow'] }} 0%, transparent 40%, {{ $selectedColor['glow'] }} 100%);"></div>

    {{-- Animated gradient border glow on hover --}}
    <div class="absolute inset-0 rounded-xl pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity duration-300" style="background: linear-gradient(135deg, {{ $selectedColor['border'] }}, transparent 50%, {{ $selectedColor['border'] }}); filter: blur(8px);"></div>

    <div class="relative z-10 flex items-stretch">
        <div class="flex items-center justify-center px-3 sm:px-4 backdrop-blur-sm" style="background: linear-gradient(135deg, {{ $selectedColor['bg'] }} 0%, {{ str_replace('0.5', '0.7', $selectedColor['bg']) }} 100%); box-shadow: inset 0 1px 0 rgba(255,255,255,0.2);">
            <x-dynamic-component :component="'feathericon-' . $icon" class="h-4 w-4 sm:h-5 sm:w-5 text-white drop-shadow-lg" />
        </div>
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-1 sm:gap-2 flex-1 px-2 py-2 sm:px-3 sm:py-2.5 min-w-0">
            <p class="text-white/90 text-[10px] sm:text-xs font-medium tracking-wide truncate uppercase" style="font-family: 'Space Grotesk', sans-serif; letter-spacing: 0.05em;">{{ $label }}</p>
            <p class="text-base sm:text-xl font-bold whitespace-nowrap drop-shadow-lg {{ $forceWhite ? 'text-white' : ($isNegative ? 'text-red-400' : 'text-emerald-400') }}" style="font-family: 'JetBrains Mono', monospace; font-feature-settings: 'tnum', 'lnum';">{{ $value }}</p>
        </div>
    </div>
</div>
