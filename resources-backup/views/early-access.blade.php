{{-- resources/views/early-access.blade.php --}}
<x-layouts.app
    title="Martingalian — Advanced Martingale Crypto Bot"
    meta-description="Dual-sided Martingale bot with 6x long & 6x short ladders, 0.35% TP, gap/SL control and a graph-workflow engine."
>
    {{-- HEAD: page-only assets --}}
    <x-slot:head>
        {{-- Using Blade Feather Icons package - no CDN needed --}}
    </x-slot:head>

    {{-- BODY TOP: dotted background only for the landing --}}
    <x-slot:bodyTop>
        <div aria-hidden="true"
             class="fixed inset-0 -z-10 bg-[radial-gradient(circle_at_1px_1px,rgba(139,30,30,0.18)_1px,transparent_0)] [background-size:24px_24px]"></div>
    </x-slot:bodyTop>

    {{-- Navbar --}}
    <x-slot:navbar>
        <x-landing.layout.navbar :show-login="true" :login-disabled="false" :show-subscribe="true" :subscribe-disabled="true" />
    </x-slot:navbar>

    {{-- Page content --}}
    <x-landing.sections.hero />

    {{-- Footer --}}
    <x-slot:footer>
        <x-landing.layout.footer />
    </x-slot:footer>

    {{-- SCRIPTS: page-only JS + helpers --}}
    <x-slot:scripts>
        @vite(['resources/js/landing.js'])
    </x-slot:scripts>
</x-layouts.app>
