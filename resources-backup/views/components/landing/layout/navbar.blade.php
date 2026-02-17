{{-- resources/views/components/landing/layout/navbar.blade.php --}}
@props([
    // Login button controls
    'showLogin'      => true,
    'loginDisabled'  => false,

    // Logout button controls
    'showLogout'     => false,

    // Subscribe button controls
    'showSubscribe'      => true,
    'subscribeDisabled'  => false,
    'subscribeHref'      => null, // Optional override; defaults to route('register') when available
])

<header id="header" class="sticky top-0 z-40 backdrop-blur bg-black/60 border-b border-white/10">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 py-2.5 sm:py-3 flex items-center justify-between">
        {{-- Brand --}}
        <a href="{{ auth()->check() ? route('home') : url('/') }}" class="flex items-center gap-2 sm:gap-3">
            <img
                src="{{ asset('images/logo.svg') }}"
                alt="Martingalian"
                class="h-6 w-auto sm:h-7 md:h-8 shrink-0 select-none"
            />
            <span class="font-semibold tracking-wider text-[13px] sm:text-sm md:text-base text-white">
                MARTINGALIAN
            </span>
        </a>

        {{-- (optional) nav links --}}
        <nav class="hidden lg:flex items-center gap-8 text-sm">
            {{-- <a href="#roadmap" class="hover:text-white/100 text-white/80">Roadmap</a> --}}
        </nav>

        {{-- Right actions --}}
        <div class="flex items-center gap-2 sm:gap-3">
            {{-- Subscribe (configurable) --}}
            @if ($showSubscribe)
                @php
                    $resolvedSubscribeHref = $subscribeHref ?? (Route::has('register') ? route('register') : null);
                @endphp

                @if ($subscribeDisabled || ! $resolvedSubscribeHref)
                    <x-ui.button
                        status="disabled"
                        size="sm"
                        icon="bell"
                        class="h-9 text-[13px] px-3 [&_svg]:h-4 [&_svg]:w-4 md:h-12 md:text-base md:px-4"
                        aria-disabled="true"
                    >
                        Subscribe
                    </x-ui.button>
                @else
                    <x-ui.button
                        href="{{ $resolvedSubscribeHref }}"
                        status="active"
                        size="sm"
                        icon="bell"
                        class="h-9 text-[13px] px-3 [&_svg]:h-4 [&_svg]:w-4 md:h-12 md:text-base md:px-4"
                    >
                        Subscribe
                    </x-ui.button>
                @endif
            @endif

            {{-- Dashboard (only for authenticated users) --}}
            @auth
                <x-ui.button
                    href="{{ route('home') }}"
                    status="active"
                    size="sm"
                    icon="activity"
                    class="h-9 text-[13px] px-3 [&_svg]:h-4 [&_svg]:w-4 md:h-12 md:text-base md:px-4"
                >
                    Dashboard
                </x-ui.button>
            @endauth

            {{-- Admin (only for admin users) --}}
            @can('access-admin')
                <x-ui.button
                    href="{{ route('admin') }}"
                    status="active"
                    size="sm"
                    theme="blue"
                    icon="shield"
                    class="h-9 text-[13px] px-3 [&_svg]:h-4 [&_svg]:w-4 md:h-12 md:text-base md:px-4"
                >
                    Admin
                </x-ui.button>
            @endcan

            {{-- Login (configurable) --}}
            @if ($showLogin)
                <x-ui.button
                    href="{{ route('login') }}"
                    :status="$loginDisabled ? 'disabled' : 'active'"
                    size="sm"
                    icon="log-in"
                    class="h-9 text-[13px] px-3 [&_svg]:h-4 [&_svg]:w-4 md:h-12 md:text-base md:px-4"
                >
                    Login
                </x-ui.button>
            @endif

            {{-- Logout (configurable) --}}
            @if ($showLogout)
                <form method="POST" action="{{ route('logout') }}" class="inline">
                    @csrf
                    <x-ui.button
                        type="submit"
                        status="active"
                        size="sm"
                        icon="log-out"
                        class="h-9 text-[13px] px-3 [&_svg]:h-4 [&_svg]:w-4 md:h-12 md:text-base md:px-4"
                    >
                        Logout
                    </x-ui.button>
                </form>
            @endif
        </div>
    </div>
</header>
