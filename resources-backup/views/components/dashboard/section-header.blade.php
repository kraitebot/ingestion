@props(['title', 'subtitle', 'icon' => null])

@php
    // Get theme-based colors using the helper
    $primaryColors = theme_map_color(theme('primary.base'));
    $primaryHoverColors = theme_map_color(theme('primary.hover'));
@endphp

<div class="flex items-start gap-3 mb-6">
    @if($icon)
        <span class="inline-grid h-10 w-10 place-items-center rounded-full {{ $primaryHoverColors['bg'] }}/10 border {{ $primaryColors['border'] }}/30 shrink-0">
            <x-dynamic-component :component="'feathericon-' . $icon" class="h-5 w-5 {{ $primaryColors['text'] }}" aria-hidden="true"/>
        </span>
    @endif
    <div>
        <h1 class="text-2xl font-extrabold text-white mb-1 drop-shadow-2xl" style="font-family: 'Space Grotesk', sans-serif; background: linear-gradient(135deg, #fff 0%, rgba(239,68,68,0.9) 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;">{{ $title }}</h1>
        <p class="text-white/70 text-xs font-light" style="font-family: 'Space Grotesk', sans-serif;">{{ $subtitle }}</p>
    </div>
</div>
