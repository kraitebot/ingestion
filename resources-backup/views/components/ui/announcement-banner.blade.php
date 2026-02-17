{{-- Global Announcement Banner --}}
@props([
    'message' => null,
    'type' => 'info', // info, warning, success, error
    'dismissible' => false,
    'show' => true,
])

@php
    // Type-based styling
    $styles = [
        'info' => 'bg-blue-500/10 border-blue-400/30 text-blue-300',
        'warning' => 'bg-yellow-500/10 border-yellow-400/30 text-yellow-300',
        'success' => 'bg-emerald-500/10 border-emerald-400/30 text-emerald-300',
        'error' => 'bg-red-500/10 border-red-400/30 text-red-300',
    ];

    $icons = [
        'info' => 'info',
        'warning' => 'alert-triangle',
        'success' => 'check-circle',
        'error' => 'alert-circle',
    ];

    $typeClass = $styles[$type] ?? $styles['info'];
    $icon = $icons[$type] ?? $icons['info'];
@endphp

@if($show && $message)
    <div class="w-full overflow-hidden transition-all duration-300"
         x-data="{ open: true }"
         x-show="open"
         x-transition:leave="transition-all ease-in-out duration-300"
         x-transition:leave-start="opacity-100 max-h-20"
         x-transition:leave-end="opacity-0 max-h-0">
        <div class="mx-2 mt-2 mb-0 px-6 py-2.5 flex items-center justify-center gap-4 rounded-lg border {{ $typeClass }}">
            {{-- Icon + Message --}}
            <div class="flex items-center gap-2.5 flex-1 justify-center">
                <x-dynamic-component :component="'feathericon-' . $icon" class="h-4 w-4 flex-shrink-0" />
                <p class="text-sm">{{ $message }}</p>
            </div>

            {{-- Dismiss Button --}}
            @if($dismissible)
                <button
                    @click="open = false"
                    class="flex-shrink-0 p-1.5 rounded-full hover:bg-white/10 transition-colors cursor-pointer"
                    aria-label="Dismiss"
                >
                    <x-feathericon-x class="h-4 w-4" />
                </button>
            @endif
        </div>
    </div>
@endif
