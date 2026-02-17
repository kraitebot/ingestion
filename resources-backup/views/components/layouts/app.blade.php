{{-- resources/views/components/layouts/app.blade.php --}}
@props([
    'title' => config('app.name'),
    'metaDescription' => null,

    // Optional SEO props
    'canonicalUrl' => null,
    'ogImage' => null,        // 1200×630
    'twitterImage' => null,   // 1200×600
    'imageAlt' => null,
    'noindex' => false,
    'twitterSite' => null,
    'twitterCreator' => null,
])

@php
    $siteName  = config('app.name', 'Martingalian');
    $canonical = $canonicalUrl ?? url()->current();
    $desc      = $metaDescription ?? 'A crypto bot for sustainable returns without babysitting charts.';
    $ogImg     = $ogImage ?? asset('images/meta/og-1200x630.png');
    $twImg     = $twitterImage ?? asset('images/meta/twitter-1200x600.png');
    $imgAlt    = $imageAlt ?? ($siteName . ' — preview');
    $robots    = $noindex ? 'noindex,nofollow' : 'index,follow';
    $ogLocale  = str_replace('-', '_', app()->getLocale());
@endphp

<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    {{-- Security --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- Primary SEO --}}
    <title>{{ $title }}</title>
    <meta name="description" content="{{ $desc }}">
    <meta name="robots" content="{{ $robots }}">
    <link rel="canonical" href="{{ $canonical }}">

    {{-- Open Graph --}}
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $siteName }}">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $desc }}">
    <meta property="og:url" content="{{ $canonical }}">
    <meta property="og:locale" content="{{ $ogLocale }}">
    <meta property="og:image" content="{{ $ogImg }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="{{ $imgAlt }}">

    {{-- Twitter Card --}}
    <meta name="twitter:card" content="summary_large_image">
    @if($twitterSite)<meta name="twitter:site" content="{{ $twitterSite }}">@endif
    @if($twitterCreator)<meta name="twitter:creator" content="{{ $twitterCreator }}">@endif
    <meta name="twitter:title" content="{{ $title }}">
    <meta name="twitter:description" content="{{ $desc }}">
    <meta name="twitter:image" content="{{ $twImg }}">
    <meta name="twitter:image:alt" content="{{ $imgAlt }}">

    {{-- Favicons --}}
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('images/favicons/apple-touch-icon.png') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/favicons/favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('images/favicons/favicon-16x16.png') }}">
    <link rel="manifest" href="{{ asset('images/favicons/site.webmanifest') }}">
    <link rel="mask-icon" href="{{ asset('images/favicons/safari-pinned-tab.svg') }}" color="#e11d48">
    <meta name="msapplication-TileColor" content="#0c0a0b">
    <meta name="theme-color" content="#0c0a0b">

    {{-- Fonts (global) --}}
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">

    {{-- Global CSS, for all webpages --}}
    @vite(['resources/css/app.css'])

    {{-- Analytics (Clarity) --}}
    <x-analytics.clarity id="q3hpe7tpeu" />

    {{-- Head slot: each page/layout injects its own CSS/JS (Vite), Turnstile, Livewire, etc. --}}
    {{ $head ?? '' }}

    @vite(['resources/js/app.js'])
</head>
<body class="min-h-screen flex flex-col text-slate-100 antialiased leading-relaxed md:leading-loose" style="background-color: {{ theme('background.base') }}">
    {{-- Body-top slot (e.g., dotted background for landing only) --}}
    {{ $bodyTop ?? '' }}

    {{-- Optional navbar --}}
    @isset($navbar)
        {{ $navbar }}
    @endisset

    {{-- Global warning banner --}}
    <x-ui.global-warning />

    {{-- Toast notification component --}}
    <x-ui.toast />

    <main class="flex-1 py-4 md:py-6">
        {{ $slot }}
    </main>

    {{-- Optional footer --}}
    @isset($footer)
        {{ $footer }}
    @endisset

    {{-- Page-end scripts slot (each page provides its own Vite JS, LivewireScripts, etc.) --}}
    {{ $scripts ?? '' }}

    {{-- Floating Screenshot Button --}}
    <button
        id="screenshot-btn"
        class="fixed bottom-6 right-6 z-[9998] inline-flex items-center gap-2 px-4 py-3 rounded-full border border-red-400/30 bg-red-500/10 text-red-400 text-sm font-medium hover:bg-red-500/20 hover:border-red-400/50 focus-visible:ring-2 focus-visible:ring-red-300 transition-all cursor-pointer shadow-lg hover:shadow-xl backdrop-blur-sm"
        title="Take Screenshot + HTML"
    >
        <x-feathericon-camera class="h-5 w-5"/>
        <span class="hidden sm:inline">Snapshot + HTML</span>
    </button>

    <script>
        // Screenshot functionality with html2canvas-pro
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
                            backgroundColor: '#0c0a0b',
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
