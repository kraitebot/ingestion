{{-- resources/views/changelog.blade.php --}}
<x-layouts.app
    title="Changelog — Martingalian"
    meta-description="Version history and updates for Martingalian trading bot"
>
    {{-- BODY TOP: dotted background --}}
    <x-slot:bodyTop>
        <div aria-hidden="true"
             class="fixed inset-0 -z-10 bg-[radial-gradient(circle_at_1px_1px,rgba(139,30,30,0.18)_1px,transparent_0)] [background-size:24px_24px]">
        </div>
    </x-slot:bodyTop>

    {{-- Navbar --}}
    <x-slot:navbar>
        <x-landing.layout.navbar
            :show-login="!auth()->check()"
            :show-subscribe="!auth()->check()"
            :show-logout="auth()->check()"
        />
    </x-slot:navbar>

    <section class="px-4 py-12">
        <div class="mx-auto max-w-4xl">
            {{-- Page Header --}}
            <div class="mb-12 text-center">
                <h1 class="text-3xl md:text-4xl font-bold text-white mb-3">Changelog</h1>
                <p class="text-base text-white/60">Version history and updates</p>
            </div>

            {{-- Changelog Card (stays in place) --}}
            <div class="rounded-2xl border border-white/10 bg-gradient-to-br from-white/[0.07] to-white/[0.02] overflow-hidden">
                {{-- Version Content Container (scrollable) --}}
                <div class="relative h-[500px] sm:h-[600px] overflow-hidden">
                    {{-- Version 0.0.1 --}}
                    <div data-version="0.0.1" class="version-entry absolute top-0 left-0 w-full h-full transition-transform duration-500 ease-out">
                        <div class="h-full overflow-y-auto px-6 md:px-8 py-6 md:py-8">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6 pb-6 border-b border-white/10">
                        <div>
                            <h2 class="text-2xl font-semibold text-white mb-2">Version 0.0.1</h2>
                            <p class="text-sm text-white/60">Released on November 7, 2024</p>
                        </div>
                        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-emerald-500/10 border border-emerald-400/30 text-emerald-300 text-xs font-medium backdrop-blur-sm w-fit">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                            Latest
                        </span>
                    </div>

                    <div class="space-y-4">
                        <div>
                            <h3 class="text-sm font-semibold text-white/80 mb-3 flex items-center gap-2">
                                <x-feathericon-plus class="h-4 w-4 text-emerald-400" aria-hidden="true"/>
                                New Features
                            </h3>
                            <ul class="space-y-2 text-sm text-white/70">
                                <li class="flex items-start gap-3">
                                    <span class="text-emerald-400 mt-1">•</span>
                                    <span>Dashboard with tabbed navigation system</span>
                                </li>
                                <li class="flex items-start gap-3">
                                    <span class="text-emerald-400 mt-1">•</span>
                                    <span>Trading accounts management with real-time analytics</span>
                                </li>
                                <li class="flex items-start gap-3">
                                    <span class="text-emerald-400 mt-1">•</span>
                                    <span>Account disable functionality with confirmation modal</span>
                                </li>
                                <li class="flex items-start gap-3">
                                    <span class="text-emerald-400 mt-1">•</span>
                                    <span>Profile management with Pushover notifications support</span>
                                </li>
                                <li class="flex items-start gap-3">
                                    <span class="text-emerald-400 mt-1">•</span>
                                    <span>Responsive design optimized for mobile and desktop</span>
                                </li>
                            </ul>
                        </div>

                        <div class="pt-4">
                            <h3 class="text-sm font-semibold text-white/80 mb-3 flex items-center gap-2">
                                <x-feathericon-layout class="h-4 w-4 text-blue-400" aria-hidden="true"/>
                                UI Components
                            </h3>
                            <ul class="space-y-2 text-sm text-white/70">
                                <li class="flex items-start gap-3">
                                    <span class="text-blue-400 mt-1">•</span>
                                    <span>Reusable button component with icon support</span>
                                </li>
                                <li class="flex items-start gap-3">
                                    <span class="text-blue-400 mt-1">•</span>
                                    <span>Input component with validation states</span>
                                </li>
                                <li class="flex items-start gap-3">
                                    <span class="text-blue-400 mt-1">•</span>
                                    <span>Modal/dialog component with blur overlay animations</span>
                                </li>
                                <li class="flex items-start gap-3">
                                    <span class="text-blue-400 mt-1">•</span>
                                    <span>Toast notification system</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                        </div>
                    </div>

                    {{-- Version 0.0.0 (Alpha) --}}
                    <div data-version="0.0.0" class="version-entry absolute top-0 left-0 w-full h-full transition-transform duration-500 ease-out pointer-events-none">
                        <div class="h-full overflow-y-auto px-6 md:px-8 py-6 md:py-8">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6 pb-6 border-b border-white/10">
                            <div>
                                <h2 class="text-2xl font-semibold text-white mb-2">Version 0.0.0</h2>
                                <p class="text-sm text-white/60">Released on October 15, 2024</p>
                            </div>
                            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-white/5 border border-white/20 text-white/60 text-xs font-medium backdrop-blur-sm w-fit">
                                Alpha
                            </span>
                        </div>

                        <div class="space-y-4">
                            <div>
                                <h3 class="text-sm font-semibold text-white/80 mb-3 flex items-center gap-2">
                                    <x-feathericon-plus class="h-4 w-4 text-emerald-400" aria-hidden="true"/>
                                    New Features
                            </h3>
                            <ul class="space-y-2 text-sm text-white/70">
                                <li class="flex items-start gap-3">
                                    <span class="text-emerald-400 mt-1">•</span>
                                    <span>Initial project setup and architecture</span>
                                </li>
                                <li class="flex items-start gap-3">
                                    <span class="text-emerald-400 mt-1">•</span>
                                    <span>Early access signup page with email notifications</span>
                                </li>
                                <li class="flex items-start gap-3">
                                    <span class="text-emerald-400 mt-1">•</span>
                                    <span>Basic authentication system</span>
                                </li>
                                <li class="flex items-start gap-3">
                                    <span class="text-emerald-400 mt-1">•</span>
                                    <span>Landing page with hero section</span>
                                </li>
                            </ul>
                        </div>

                        <div class="pt-4">
                            <h3 class="text-sm font-semibold text-white/80 mb-3 flex items-center gap-2">
                                <x-feathericon-layout class="h-4 w-4 text-blue-400" aria-hidden="true"/>
                                Design System
                            </h3>
                            <ul class="space-y-2 text-sm text-white/70">
                                <li class="flex items-start gap-3">
                                    <span class="text-blue-400 mt-1">•</span>
                                    <span>Dark glassmorphism theme with red accents</span>
                                </li>
                                <li class="flex items-start gap-3">
                                    <span class="text-blue-400 mt-1">•</span>
                                    <span>Tailwind CSS v4 setup and configuration</span>
                                </li>
                                <li class="flex items-start gap-3">
                                    <span class="text-blue-400 mt-1">•</span>
                                    <span>Responsive navigation with logo and brand identity</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                        </div>
                    </div>
                </div>

                {{-- Fixed Version Navigation (always visible) --}}
                <div class="border-t border-white/10 px-4 md:px-6 py-3 md:py-4 flex justify-between items-center gap-2">
                    <button
                        type="button"
                        data-nav="next"
                        class="inline-flex items-center gap-1.5 md:gap-2 text-xs sm:text-sm text-white/60 hover:text-red-400 transition-colors cursor-pointer disabled:opacity-30 disabled:cursor-not-allowed min-h-[44px] px-2"
                    >
                        <x-feathericon-chevron-up class="h-4 w-4" aria-hidden="true"/>
                        <span class="hidden xs:inline">Next Version</span>
                        <span class="xs:hidden">Next</span>
                    </button>

                    <button
                        type="button"
                        data-nav="previous"
                        class="inline-flex items-center gap-1.5 md:gap-2 text-xs sm:text-sm text-white/60 hover:text-red-400 transition-colors cursor-pointer disabled:opacity-30 disabled:cursor-not-allowed min-h-[44px] px-2"
                    >
                        <span class="hidden xs:inline">Previous Version</span>
                        <span class="xs:hidden">Previous</span>
                        <x-feathericon-chevron-down class="h-4 w-4" aria-hidden="true"/>
                    </button>
                </div>
            </div>

                {{-- Future Version Example (commented out for now) --}}
                {{--
                <div class="rounded-2xl border border-white/10 bg-gradient-to-br from-white/[0.07] to-white/[0.02] p-6 md:p-8">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6 pb-6 border-b border-white/10">
                        <div>
                            <h2 class="text-2xl font-semibold text-white mb-2">Version 0.1.0</h2>
                            <p class="text-sm text-white/60">Released on [Date]</p>
                        </div>
                    </div>

                    <div class="space-y-4">
                        <div>
                            <h3 class="text-sm font-semibold text-white/80 mb-3 flex items-center gap-2">
                                <x-feathericon-plus class="h-4 w-4 text-emerald-400" aria-hidden="true"/>
                                New Features
                            </h3>
                            <ul class="space-y-2 text-sm text-white/70">
                                <li class="flex items-start gap-3">
                                    <span class="text-emerald-400 mt-1">•</span>
                                    <span>Feature description here</span>
                                </li>
                            </ul>
                        </div>

                        <div class="pt-4">
                            <h3 class="text-sm font-semibold text-white/80 mb-3 flex items-center gap-2">
                                <x-feathericon-zap class="h-4 w-4 text-yellow-400" aria-hidden="true"/>
                                Improvements
                            </h3>
                            <ul class="space-y-2 text-sm text-white/70">
                                <li class="flex items-start gap-3">
                                    <span class="text-yellow-400 mt-1">•</span>
                                    <span>Improvement description here</span>
                                </li>
                            </ul>
                        </div>

                        <div class="pt-4">
                            <h3 class="text-sm font-semibold text-white/80 mb-3 flex items-center gap-2">
                                <x-feathericon-tool class="h-4 w-4 text-red-400" aria-hidden="true"/>
                                Bug Fixes
                            </h3>
                            <ul class="space-y-2 text-sm text-white/70">
                                <li class="flex items-start gap-3">
                                    <span class="text-red-400 mt-1">•</span>
                                    <span>Bug fix description here</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
                --}}
            </div>

            {{-- Back Link --}}
            <div class="mt-12 text-center">
                <a
                    href="{{ auth()->check() ? route('home') : url('/') }}"
                    class="inline-flex items-center gap-2 text-sm text-white/60 hover:text-red-400 transition-colors"
                >
                    <x-feathericon-arrow-left class="h-4 w-4" aria-hidden="true"/>
                    Back to {{ auth()->check() ? 'Dashboard' : 'Home' }}
                </a>
            </div>
        </div>
    </section>

    <x-slot:footer>
        <x-landing.layout.footer />
    </x-slot:footer>

    <x-slot:scripts>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                if (typeof feather !== 'undefined') {
                    feather.replace();
                }

                // Version navigation (slide style)
                const versions = document.querySelectorAll('.version-entry');
                const prevButton = document.querySelector('[data-nav="previous"]');
                const nextButton = document.querySelector('[data-nav="next"]');
                let currentIndex = 0;
                let isAnimating = false;

                // Update button states
                function updateButtons() {
                    if (nextButton) {
                        nextButton.disabled = currentIndex === 0;
                    }
                    if (prevButton) {
                        prevButton.disabled = currentIndex === versions.length - 1;
                    }
                }

                // Initialize all versions with correct positions
                versions.forEach(function(version, index) {
                    // Disable transitions temporarily for initial positioning
                    version.style.transition = 'none';

                    if (index === 0) {
                        version.style.transform = 'translateY(0)';
                        version.classList.remove('pointer-events-none');
                        version.classList.add('pointer-events-auto');
                    } else {
                        version.style.transform = 'translateY(100%)';
                        version.classList.add('pointer-events-none');
                        version.classList.remove('pointer-events-auto');
                    }
                });

                // Force reflow to apply positions
                versions[0].offsetHeight;

                // Re-enable transitions
                versions.forEach(function(version) {
                    version.style.transition = '';
                });

                updateButtons();

                // Handle navigation buttons
                document.addEventListener('click', function(e) {
                    const navButton = e.target.closest('[data-nav]');
                    if (!navButton || isAnimating || navButton.disabled) return;

                    const direction = navButton.getAttribute('data-nav');
                    let nextIndex = currentIndex;

                    if (direction === 'previous' && currentIndex < versions.length - 1) {
                        nextIndex = currentIndex + 1;
                    } else if (direction === 'next' && currentIndex > 0) {
                        nextIndex = currentIndex - 1;
                    }

                    if (nextIndex !== currentIndex) {
                        isAnimating = true;

                        const currentVersion = versions[currentIndex];
                        const nextVersion = versions[nextIndex];

                        // Enable next version for animation and bring to front
                        nextVersion.classList.add('pointer-events-auto');
                        currentVersion.style.zIndex = '1';
                        nextVersion.style.zIndex = '2';

                        if (direction === 'previous') {
                            // Going to older version: current slides up, next comes from below
                            currentVersion.style.transform = 'translateY(-100%)';
                            nextVersion.style.transform = 'translateY(0)';
                        } else {
                            // Going to newer version: current slides down, next comes from above
                            currentVersion.style.transform = 'translateY(100%)';
                            nextVersion.style.transform = 'translateY(0)';
                        }

                        setTimeout(function() {
                            // Disable interaction on old version and reset position
                            currentVersion.classList.add('pointer-events-none');
                            if (direction === 'previous') {
                                currentVersion.style.transform = 'translateY(-100%)';
                            } else {
                                currentVersion.style.transform = 'translateY(100%)';
                            }

                            // Scroll to top of content (find the scrollable inner div)
                            const scrollableContent = nextVersion.querySelector('.overflow-y-auto');
                            if (scrollableContent) {
                                scrollableContent.scrollTop = 0;
                            }

                            currentIndex = nextIndex;
                            isAnimating = false;

                            // Update button states
                            updateButtons();

                            // Update feather icons
                            if (typeof feather !== 'undefined') {
                                feather.replace();
                            }
                        }, 500);
                    }
                });
            });
        </script>
    </x-slot:scripts>
</x-layouts.app>
