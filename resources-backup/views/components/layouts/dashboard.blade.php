{{-- Dashboard Layout with Sidebar --}}
@props([
    'title' => config('app.name'),
])

@php
    // Extract Alpine directives from attributes
    $alpineData = $attributes->get('x-data');
    $alpineInit = $attributes->get('x-init');

    // Get theme-based colors using the helper
    $primaryColors = theme_map_color(theme('primary.base'));
    $primaryHoverColors = theme_map_color(theme('primary.hover'));
@endphp

<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title }}</title>

    {{-- Fonts --}}
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">

    {{-- CSS --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="text-white antialiased" style="background-color: {{ theme('background.elevated') }}">
    {{-- Toast notifications --}}
    <x-ui.toast />

    {{-- Main container: flex row --}}
    <div class="flex h-screen overflow-hidden"
         x-data="{
             sidebarOpen: false,
             @if($alpineData) ...{{ $alpineData }}, @endif
             closeSidebar() { this.sidebarOpen = false; }
         }"
         @if($alpineInit) x-init="{{ $alpineInit }}" @endif>

        {{-- Mobile Menu Button --}}
        <button
            @click="sidebarOpen = !sidebarOpen"
            class="fixed top-1/2 -translate-y-1/2 left-0 z-50 lg:hidden inline-flex items-center justify-center h-12 w-8 rounded-r-lg border-r border-t border-b {{ $primaryColors['border'] }}/30 {{ $primaryHoverColors['bg'] }}/10 text-white {{ $primaryHoverColors['hover'] }}/10 hover:{{ $primaryColors['border'] }}/30 transition-all"
        >
            <x-feathericon-menu class="h-4 w-4" x-show="!sidebarOpen" />
            <x-feathericon-x class="h-4 w-4" x-show="sidebarOpen" x-cloak />
        </button>

        {{-- Mobile Overlay --}}
        <div
            x-show="sidebarOpen"
            @click="sidebarOpen = false"
            x-transition:enter="transition-opacity ease-linear duration-300"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition-opacity ease-linear duration-300"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="fixed inset-0 bg-black/50 z-40 lg:hidden"
            x-cloak
        ></div>

        {{-- Sidebar (left, fixed width) --}}
        <aside
            class="fixed lg:static inset-y-0 left-0 z-40 w-64 flex-shrink-0 transform transition-transform duration-300 ease-in-out lg:translate-x-0"
            :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
            style="background-color: {{ theme('background.sidebar') }}"
        >
            {{ $sidebar ?? '' }}
        </aside>

        {{-- Main content (right, flexible) --}}
        <main class="flex-1 flex flex-col overflow-hidden relative">
            {{-- Red dotted background --}}
            <div aria-hidden="true" class="fixed inset-0 -z-10 bg-[radial-gradient(circle_at_1px_1px,rgba(139,30,30,0.25)_1px,transparent_0)] [background-size:24px_24px] pointer-events-none"></div>

            {{-- Global Announcement Banners (disabled) --}}
            {{--
            <x-ui.announcement-banner
                message="Black Friday PROMO: Get unlimited version at 50% discount!"
                type="warning"
                :dismissible="true"
            />

            <x-ui.announcement-banner
                message="Attention: Your email is not reachable, please verify asap!"
                type="error"
                :dismissible="true"
            />
            --}}

            {{-- Main scrollable content --}}
            <div class="flex-1 overflow-y-auto">
                {{ $slot }}
            </div>
        </main>
    </div>

    {{-- Floating Screenshot Button --}}
    @php
        $primaryLightColors = theme_map_color(theme('primary.light'));
    @endphp
    <button
        id="screenshot-btn"
        class="fixed bottom-6 right-6 z-[9998] inline-flex items-center gap-2 px-4 py-3 rounded-full border {{ $primaryLightColors['border'] }}/30 {{ $primaryColors['bg'] }}/10 {{ $primaryLightColors['text'] }} text-sm font-medium {{ $primaryColors['hover'] }}/20 hover:{{ $primaryLightColors['border'] }}/50 focus-visible:ring-2 focus-visible:ring-{{ str_replace('text-', '', $primaryLightColors['text']) }} transition-all cursor-pointer shadow-lg hover:shadow-xl backdrop-blur-sm"
        title="Take Screenshot + HTML"
    >
        <x-feathericon-camera class="h-5 w-5"/>
        <span class="hidden sm:inline">Snapshot + HTML</span>
    </button>

    {{-- Scripts slot --}}
    {{ $scripts ?? '' }}

    {{-- Sliding nav indicator animation --}}
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const indicator = document.getElementById('nav-indicator');
            const navItems = document.querySelectorAll('[data-nav-item]');
            let animationStartTime = null;

            console.log('🎯 Nav indicator initialized');
            console.log('Indicator element:', indicator);
            console.log('Nav items found:', navItems.length);

            // Listen for animation end (just for logging)
            if (indicator) {
                indicator.addEventListener('transitionend', function(e) {
                    if (animationStartTime && e.propertyName === 'transform') {
                        const duration = Date.now() - animationStartTime;
                        console.log('⏱️ Animation completed in:', duration + 'ms');
                        animationStartTime = null;
                    }
                });
            }

            function updateIndicator(target) {
                if (!indicator || !target) {
                    console.log('❌ Missing indicator or target');
                    return;
                }

                const container = target.closest('.space-y-1');
                const containerRect = container.getBoundingClientRect();
                const targetRect = target.getBoundingClientRect();

                // Calculate position relative to container
                const top = targetRect.top - containerRect.top;

                console.log('📍 Moving indicator to position:', top);
                console.log('Current transition:', indicator.style.transition);

                // Mark animation start time
                animationStartTime = Date.now();
                console.log('⏱️ Animation started at:', animationStartTime);

                // Animate to new position
                indicator.style.opacity = '1';
                indicator.style.transform = `translateY(${top}px)`;
            }

            function findActiveItemBySection(section) {
                console.log('Looking for nav item with section:', section);

                // Find nav item with matching data-section
                const item = Array.from(navItems).find(item => {
                    const itemSection = item.getAttribute('data-section');
                    console.log('Checking item:', itemSection);
                    return itemSection === section;
                });

                console.log('Found item:', item);
                return item;
            }

            // Listen for dashboard ready event (dispatched from Alpine after rendering)
            window.addEventListener('dashboard-ready', (e) => {
                console.log('🎉 Dashboard ready event received!');
                const activeSection = e.detail.activeSection;
                console.log('Active section from event:', activeSection);

                const activeItem = findActiveItemBySection(activeSection);

                if (activeItem) {
                    console.log('⚡ Setting initial position (no animation)');
                    indicator.style.transition = 'none';
                    updateIndicator(activeItem);

                    // Re-enable transitions after initial position
                    requestAnimationFrame(() => {
                        indicator.style.transition = 'all 300ms cubic-bezier(0.4, 0.0, 0.2, 1)';
                        console.log('✅ Transitions re-enabled:', indicator.style.transition);
                    });
                } else {
                    console.log('❌ Could not find active item for section:', activeSection);
                }
            });

            // Watch for clicks to update indicator
            navItems.forEach(item => {
                item.addEventListener('click', function(e) {
                    console.log('🖱️ Nav item clicked:', this.textContent.trim());

                    // Update indicator immediately on click
                    updateIndicator(this);
                });
            });
        });
    </script>

    {{-- Screenshot functionality --}}
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const screenshotBtn = document.getElementById('screenshot-btn');

            if (screenshotBtn) {
                // Re-initialize Feather icons for the button
                if (window.feather && typeof window.feather.replace === 'function') {
                    window.feather.replace();
                }

                screenshotBtn.addEventListener('click', async function() {
                    try {
                        // Disable button during capture
                        screenshotBtn.disabled = true;
                        screenshotBtn.style.opacity = '0.5';
                        screenshotBtn.style.cursor = 'wait';

                        console.log('Starting screenshot with html2canvas-pro...');

                        // Capture the viewport with html2canvas-pro (supports oklch!)
                        const canvas = await window.html2canvas(document.body, {
                            allowTaint: true,
                            useCORS: true,
                            logging: false,
                            scale: 2,
                            backgroundColor: '#1a1517',
                            scrollY: -window.scrollY,
                            scrollX: -window.scrollX,
                            windowWidth: document.documentElement.scrollWidth,
                            windowHeight: document.documentElement.scrollHeight
                        });

                        console.log('Screenshot captured successfully!');

                        // Convert canvas to blob
                        const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/png'));

                        // Create FormData
                        const formData = new FormData();
                        const timestamp = new Date().toISOString().replace(/[:.]/g, '-');
                        formData.append('images[]', blob, `screenshot-${timestamp}.png`);

                        // Also capture and upload the HTML source
                        const htmlSource = document.documentElement.outerHTML;
                        const htmlBlob = new Blob([htmlSource], { type: 'text/html' });
                        formData.append('images[]', htmlBlob, `source-${timestamp}.html`);

                        // Get CSRF token
                        const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

                        // Upload to server
                        const response = await fetch('/debug-images', {
                            method: 'POST',
                            headers: {
                                'X-CSRF-TOKEN': csrfToken,
                                'Accept': 'application/json'
                            },
                            body: formData
                        });

                        const result = await response.json();

                        if (response.ok && result.success) {
                            window.showToast('Screenshot and HTML source saved successfully!', 'success', 5000);
                        } else {
                            window.showToast(result.message || 'Failed to save snapshot', 'error', 5000);
                        }
                    } catch (error) {
                        console.error('Screenshot error:', error);
                        const errorMsg = error.message || error.toString() || 'Unknown error';
                        window.showToast('Failed to capture screenshot: ' + errorMsg, 'error', 5000);
                    } finally {
                        // Re-enable button
                        screenshotBtn.disabled = false;
                        screenshotBtn.style.opacity = '1';
                        screenshotBtn.style.cursor = 'pointer';
                    }
                });
            }
        });
    </script>
</body>
</html>
