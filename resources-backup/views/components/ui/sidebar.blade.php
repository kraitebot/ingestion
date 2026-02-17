{{-- Sidebar Component --}}
<div class="flex flex-col h-full relative">
    {{-- Right edge fade gradient --}}
    <div class="absolute top-0 bottom-0 right-0 w-16 bg-gradient-to-l from-black/5 via-transparent to-transparent pointer-events-none z-10"></div>

    {{-- Logo section --}}
    <div class="p-6 border-b border-white/10">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 bg-red-500 rounded-lg flex items-center justify-center text-white font-bold text-xl">
                M
            </div>
            <span class="text-lg font-semibold text-white">Martingalian</span>
        </div>
    </div>

    {{-- Main navigation --}}
    <nav class="flex-1 px-3 py-4 overflow-y-auto relative" id="sidebar-nav">
        <div class="mb-6">
            <p class="px-3 mb-2 text-xs font-semibold text-white/40 uppercase tracking-wider">Main</p>
            <div class="space-y-1 relative">
                {{-- Sliding active indicator --}}
                <div id="nav-indicator" class="absolute left-0 w-full h-[42px] rounded-lg bg-red-500/10 border border-red-400/30" style="top: 0; opacity: 0; transition: all 300ms cubic-bezier(0.4, 0.0, 0.2, 1);"></div>

                {{ $slot }}
            </div>
        </div>
    </nav>

    {{-- User section (bottom) --}}
    <div class="p-4">
        <button class="w-full flex items-center gap-3 px-4 py-3 rounded-xl border border-white/10 hover:bg-white/5 transition-colors group" style="background-color: {{ theme('background.button') }}">
            {{-- Avatar --}}
            <img
                src="https://ui-avatars.com/api/?name={{ urlencode(auth()->user()->name) }}&color=ffffff&background={{ str_replace('#', '', config('theme.primary.text') === 'red-400' ? 'ef4444' : '3b82f6') }}"
                alt="{{ auth()->user()->name }}"
                class="w-11 h-11 rounded-full flex-shrink-0"
            />

            {{-- Name + Email --}}
            <div class="flex-1 min-w-0 text-left">
                <p class="text-sm font-medium text-white truncate">{{ auth()->user()->name }}</p>
                <p class="text-xs text-white/50 truncate">{{ auth()->user()->email }}</p>
            </div>

            {{-- Chevron Up --}}
            <x-feathericon-chevron-up class="h-4 w-4 text-white/40 group-hover:text-white/60 transition-colors flex-shrink-0" />
        </button>
    </div>
</div>
