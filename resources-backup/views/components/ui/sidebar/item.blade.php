{{-- Sidebar Navigation Item --}}
@props([
    'section' => null,
    'icon' => null,
    'label' => '',
])

<button
    type="button"
    @click="$dispatch('navigate-to', '{{ $section }}'); closeSidebar();"
    data-nav-item
    data-section="{{ $section }}"
    class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg relative z-10 w-full text-left cursor-pointer transition-all duration-300 text-white/60 hover:text-white"
    style="transition-property: color, font-weight;"
>
    @if($icon)
        <x-dynamic-component :component="'feathericon-' . $icon" class="h-5 w-5 flex-shrink-0" />
    @endif
    <span class="text-sm">{{ $label }}</span>
</button>
