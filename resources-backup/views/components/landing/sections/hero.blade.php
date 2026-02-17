{{-- resources/views/components/landing/sections/hero.blade.php --}}
<section class="relative overflow-hidden" id="top">
    {{-- Global dotted background is provided by the layout; no local background here --}}
    <div class="mx-auto max-w-7xl px-6 pt-1 pb-1 lg:pt-1 lg:pb-1">
        <div class="grid lg:grid-cols-2 gap-10 items-center">
            {{-- LEFT: copy column --}}
            <div class="order-1 lg:order-1 text-center lg:text-left max-w-2xl space-y-6 md:space-y-7">
                {{-- Title FIRST on mobile --}}
                <h1 class="text-4xl/tight md:text-5xl/tight font-bold text-white">
                    A unique automated strategy to maximize your profits and minimize your losses
                </h1>

                {{-- IMAGE SECOND only on mobile (desktop image stays on the right) --}}
                <div class="block lg:hidden">
                    <img class="w-full max-w-xs sm:max-w-sm mx-auto" src="{{ asset('images/hero.png') }}" alt="header">
                </div>

                {{-- Subtitle THIRD on mobile --}}
                <p class="text-white/70 text-base md:text-[17px]">
                    Our crypto trading bot runs 24/7 — Plug in your API keys and watch it trade. No drama, no FOMO, just cold-blooded execution bringing you sustainable profits every day
                </p>

                {{-- Features (vertical list) --}}
                <!--
                <div class="space-y-3 text-left" role="list">
                    <div class="flex items-start gap-3" role="listitem">
                        <span class="grid place-items-center h-8 w-8 aspect-square shrink-0 rounded-full bg-red-500/10 border border-red-400/30">
                            <span aria-hidden="true"><x-feathericon-layers class="h-4 w-4"/></span>
                        </span>
                        <span class="text-sm leading-5 text-white/70">
                            <span class="text-white font-medium">Fully automated</span> — Connect your API Keys, go to sleep & check profits next day
                        </span>
                    </div>

                    <div class="flex items-start gap-3" role="listitem">
                        <span class="grid place-items-center h-8 w-8 aspect-square shrink-0 rounded-full bg-red-500/10 border border-red-400/30">
                            <span aria-hidden="true"><x-feathericon-trending-up class="h-4 w-4"/></span>
                        </span>
                        <span class="text-sm leading-5 text-white/70">
                            <span class="text-white font-medium">Works in both market trends</span> — Bear & Bulish markets, open trades everyday
                        </span>
                    </div>

                    <div class="flex items-start gap-3" role="listitem">
                        <span class="grid place-items-center h-8 w-8 aspect-square shrink-0 rounded-full bg-red-500/10 border border-red-400/30">
                            <span aria-hidden="true"><x-feathericon-shield class="h-4 w-4"/></span>
                        </span>
                        <span class="text-sm leading-5 text-white/70">
                            <span class="text-white font-medium">Engineered to avoid liquidation</span> — Smart Stop-Loss to minimize max pain results
                        </span>
                    </div>
                </div>
                -->
                <div class="space-y-3 text-left" role="list">
                    <div class="flex items-center gap-3" role="listitem">
                        <span class="grid place-items-center h-8 w-8 aspect-square shrink-0 rounded-full bg-red-500/10 border border-red-400/30 text-red-400">
                            <span aria-hidden="true"><x-feathericon-layers class="h-4 w-4"/></span>
                        </span>
                        <span class="text-sm leading-5 text-white/70">
                            <span class="text-white font-medium">Fully automated 24/7</span> — Plug & Play, zero configuration required
                        </span>
                    </div>

                    <div class="flex items-center gap-3" role="listitem">
                        <span class="grid place-items-center h-8 w-8 aspect-square shrink-0 rounded-full bg-red-500/10 border border-red-400/30 text-red-400">
                            <span aria-hidden="true"><x-feathericon-trending-up class="h-4 w-4"/></span>
                        </span>
                        <span class="text-sm leading-5 text-white/70">
                            <span class="text-white font-medium">Works in any market</span> — Bear or Bull, profitable trades every day
                        </span>
                    </div>

                    <div class="flex items-center gap-3" role="listitem">
                        <span class="grid place-items-center h-8 w-8 aspect-square shrink-0 rounded-full bg-red-500/10 border border-red-400/30 text-red-400">
                            <span aria-hidden="true"><x-feathericon-shield class="h-4 w-4"/></span>
                        </span>
                        <span class="text-sm leading-5 text-white/70">
                            <span class="text-white font-medium">Engineered to avoid liquidation</span> — Smart Stop-Loss minimizes your risk
                        </span>
                    </div>

                    <div class="flex items-center gap-3" role="listitem">
                        <span class="grid place-items-center h-8 w-8 aspect-square shrink-0 rounded-full bg-red-500/10 border border-red-400/30 text-red-400">
                            <span aria-hidden="true"><x-feathericon-unlock class="h-4 w-4"/></span>
                        </span>
                        <span class="text-sm leading-5 text-white/70">
                            <span class="text-white font-medium">No lock-in contracts</span> — Cancel anytime, no questions asked
                        </span>
                    </div>

                    <div class="flex items-center gap-3" role="listitem">
                        <span class="grid place-items-center h-8 w-8 aspect-square shrink-0 rounded-full bg-red-500/10 border border-red-400/30 text-red-400">
                            <span aria-hidden="true"><x-feathericon-key class="h-4 w-4"/></span>
                        </span>
                        <span class="text-sm leading-5 text-white/70">
                            <span class="text-white font-medium">Your profits stay yours</span> — Just connect API keys, we never touch your funds
                        </span>
                    </div>
                </div>

                {{-- Early access form (plain Blade include; Livewire removed) --}}
                @include('components.landing.sections.forms.early-access.early-access-form')

                <!--
                <div class="flex flex-col items-start justify-center lg:justify-start">
                    <x-ui.button status="disabled" icon="film" class="px-5 py-3 font-semibold group">
                        How it Works
                    </x-ui.button>
                    <p class="mt-2 text-sm text-white/60 flex items-center justify-start">
                        <span class="mr-2 text-white/60"><x-feathericon-clock class="h-4 w-4"/></span>
                        Video will be released soon
                    </p>
                </div>
                -->
            </div>

            {{-- RIGHT: desktop image only --}}
            <div class="order-2 lg:order-2">
                <img class="hidden lg:block w-full max-w-xl mx-auto" src="{{ asset('images/hero.png') }}" alt="header">
            </div>
        </div>

        <div class="mt-12 grid lg:grid-cols-12 gap-6 items-stretch">
            <div class="lg:col-span-8 rounded-2xl border border-white/10 bg-white/5 p-6 flex flex-col h-full min-h-[160px]">
                <div class="flex items-center justify-between text-xs">
                    <div class="flex items-center gap-2">
                        <span class="h-8 w-8 inline-grid place-items-center rounded-full bg-red-500/10 border border-red-400/30 text-red-400">
                            <span><x-feathericon-map class="h-4 w-4"/></span>
                        </span>
                        <span class="uppercase tracking-wider text-white/70">Roadmap</span>
                    </div>
                    <span class="inline-flex items-center gap-1 rounded-full border border-red-400/30 bg-red-500/10 px-2 py-1 text-red-300">
                        <span><x-feathericon-flag class="h-4 w-4"/></span>
                        <span class="text-[11px]">Current: <b>In Development</b></span>
                    </span>
                </div>
                <div class="mt-4">
                    <div class="relative h-2 w-full rounded-full bg-white/10 overflow-hidden">
                        <div class="absolute inset-y-0 left-0 bg-[linear-gradient(to_right,rgba(239,68,68,.9),rgba(239,68,68,.55))]" style="width:20%"></div>
                        <span class="absolute top-1/2 -translate-y-1/2 left-0 h-3 w-3 rounded-full bg-red-400 ring-4 ring-red-400/25"></span>
                        <span class="absolute top-1/2 -translate-y-1/2 left-1/3 h-3 w-3 rounded-full bg-red-400 ring-4 ring-red-400/25"></span>
                        <span class="absolute top-1/2 -translate-y-1/2 left-2/3 h-3 w-3 rounded-full bg-white/30 ring-4 ring-white/10"></span>
                        <span class="absolute top-1/2 -translate-y-1/2 left-full -translate-x-full h-3 w-3 rounded-full bg-white/30 ring-4 ring-white/10"></span>
                    </div>
                    <div class="mt-3 grid grid-cols-4 text-center text-[11px] text-white/60">
                        <span>In Development</span>
                        <span>Alpha Testing</span>
                        <span>Early-access (beta)</span>
                        <span>Public Release</span>
                    </div>
                </div>
                <div class="mt-auto"></div>
            </div>

            <div class="lg:col-span-2 rounded-2xl border border-white/10 bg-white/5 p-5 flex flex-col min-h-[160px]">
                <div class="flex items-center gap-2">
                    <span class="h-8 w-8 inline-grid place-items-center rounded-full bg-red-500/10 border border-red-400/30 text-red-400">
                        <span><x-feathericon-activity class="h-4 w-4"/></span>
                    </span>
                    <div class="text-[11px] text-white/60 uppercase tracking-wider">Current PnL</div>
                </div>
                <div class="mt-4 text-center">
                    <div class="text-4xl font-extrabold text-white">---</div>
                </div>
                <div class="mt-2 text-center text-[11px] text-white/60">Coming soon</div>
            </div>

            <div class="lg:col-span-2 rounded-2xl border border-white/10 bg-white/5 p-5 flex flex-col min-h-[160px]">
                <div class="flex items-center gap-2">
                    <span class="h-8 w-8 inline-grid place-items-center rounded-full bg-red-500/10 border border-red-400/30 text-red-400">
                        <span><x-feathericon-flag class="h-4 w-4"/></span>
                    </span>
                    <div class="text-[11px] text-white/60 uppercase tracking-wider">Launching in</div>
                </div>
                <div class="mt-4 text-center">
                    <div class="text-4xl font-extrabold text-white">
                        <span data-days-countdown="2025-12-31T00:00:00Z" aria-live="polite">0</span>d
                    </div>
                </div>
                <div class="mt-2 text-center text-[11px] text-white/60">Target: Dec 31, 2025</div>
            </div>
        </div>
    </div>
</section>
