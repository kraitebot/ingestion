<x-layouts.app title="Logo Preview - Martingalian">
    <div class="container mx-auto max-w-7xl px-4 py-12">
        <div class="text-center mb-12">
            <h1 class="text-3xl md:text-4xl font-bold text-white mb-4">Martingalian Logo</h1>
            <p class="text-white/60">Preview of the logo at various resolutions</p>
        </div>

        {{-- MAIN LOGO SECTION --}}
        <div class="mb-16 p-8 rounded-2xl border border-emerald-400/30 bg-emerald-500/10">
            <h2 class="text-2xl font-semibold text-emerald-200 mb-6 text-center">Official Logo (SVG)</h2>
            <p class="text-emerald-200/70 text-sm text-center mb-8 max-w-2xl mx-auto">
                Scalable vector format - perfect quality at any size
            </p>

            {{-- Extra Large (512x512) --}}
            <div class="mb-12">
                <h3 class="text-lg font-semibold text-emerald-200 mb-4 text-center">Extra Large - 512x512</h3>
                <div class="flex justify-center">
                    <div class="p-8 rounded-2xl bg-[#0c0a0b] inline-block">
                        <img src="{{ asset('images/logo.svg') }}" alt="Martingalian Logo" class="w-[512px] h-auto">
                    </div>
                </div>
                <p class="text-sm text-emerald-200/60 text-center mt-4">Perfect for: App icons, social media, large displays</p>
            </div>

            {{-- Large & Medium Grid --}}
            <div class="grid md:grid-cols-2 gap-8 mb-12">
                {{-- Large (256x256) --}}
                <div class="flex flex-col items-center gap-4">
                    <h3 class="text-lg font-semibold text-emerald-200">Large - 256x256</h3>
                    <div class="p-6 rounded-2xl bg-[#0c0a0b]">
                        <img src="{{ asset('images/logo.svg') }}" alt="Martingalian Logo" class="w-64 h-auto">
                    </div>
                    <p class="text-sm text-emerald-200/60 text-center">Perfect for: Headers, profiles</p>
                </div>

                {{-- Medium (128x128) --}}
                <div class="flex flex-col items-center gap-4">
                    <h3 class="text-lg font-semibold text-emerald-200">Medium - 128x128</h3>
                    <div class="p-6 rounded-2xl bg-[#0c0a0b]">
                        <img src="{{ asset('images/logo.svg') }}" alt="Martingalian Logo" class="w-32 h-auto">
                    </div>
                    <p class="text-sm text-emerald-200/60 text-center">Perfect for: Avatars, thumbnails</p>
                </div>
            </div>

            {{-- Small Sizes Grid --}}
            <div class="mb-12">
                <h3 class="text-lg font-semibold text-emerald-200 mb-4 text-center">Small Sizes</h3>
                <div class="flex flex-wrap justify-center items-center gap-12 p-8 rounded-2xl bg-[#0c0a0b]">
                    <div class="flex flex-col items-center gap-3">
                        <img src="{{ asset('images/logo.svg') }}" alt="Martingalian Logo" class="w-16 h-auto">
                        <span class="text-xs text-white/60">64x64</span>
                        <span class="text-xs text-emerald-200/60">App bars</span>
                    </div>
                    <div class="flex flex-col items-center gap-3">
                        <img src="{{ asset('images/logo.svg') }}" alt="Martingalian Logo" class="w-12 h-auto">
                        <span class="text-xs text-white/60">48x48</span>
                        <span class="text-xs text-emerald-200/60">Small icons</span>
                    </div>
                    <div class="flex flex-col items-center gap-3">
                        <img src="{{ asset('images/logo.svg') }}" alt="Martingalian Logo" class="w-8 h-auto">
                        <span class="text-xs text-white/60">32x32</span>
                        <span class="text-xs text-emerald-200/60">Favicons</span>
                    </div>
                    <div class="flex flex-col items-center gap-3">
                        <img src="{{ asset('images/logo.svg') }}" alt="Martingalian Logo" class="w-4 h-auto">
                        <span class="text-xs text-white/60">16x16</span>
                        <span class="text-xs text-emerald-200/60">Browser tabs</span>
                    </div>
                </div>
                <p class="text-sm text-emerald-200/60 text-center mt-4">Favicon sizes - still clear and recognizable</p>
            </div>

            {{-- File Info --}}
            <div class="p-4 rounded-lg bg-black/30 text-emerald-200/80 text-sm">
                <p class="font-semibold mb-2">📍 File Locations:</p>
                <div class="space-y-1">
                    <div><code class="bg-black/40 px-2 py-1 rounded">public/images/logo.svg</code> <span class="text-emerald-200/60">(Primary - Vector)</span></div>
                </div>
            </div>
        </div>

        {{-- BACKGROUND TESTS --}}
        <div class="mb-16 p-8 rounded-2xl border border-white/10 bg-white/5">
            <h2 class="text-xl font-semibold text-white mb-6 text-center">Background Compatibility Tests</h2>
            <p class="text-white/60 text-sm text-center mb-8">Testing logo appearance on different backgrounds</p>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-8">
                <div class="flex flex-col items-center gap-3">
                    <div class="p-6 rounded-xl bg-[#0c0a0b] w-full flex justify-center">
                        <img src="{{ asset('images/logo.svg') }}" alt="Martingalian Logo" class="w-32 h-auto">
                    </div>
                    <p class="text-xs text-white/60 text-center">Dark (brand)</p>
                </div>
                <div class="flex flex-col items-center gap-3">
                    <div class="p-6 rounded-xl bg-white w-full flex justify-center">
                        <img src="{{ asset('images/logo.svg') }}" alt="Martingalian Logo" class="w-32 h-auto">
                    </div>
                    <p class="text-xs text-white/60 text-center">White</p>
                </div>
                <div class="flex flex-col items-center gap-3">
                    <div class="p-6 rounded-xl bg-gray-800 w-full flex justify-center">
                        <img src="{{ asset('images/logo.svg') }}" alt="Martingalian Logo" class="w-32 h-auto">
                    </div>
                    <p class="text-xs text-white/60 text-center">Gray</p>
                </div>
                <div class="flex flex-col items-center gap-3">
                    <div class="p-6 rounded-xl bg-black w-full flex justify-center">
                        <img src="{{ asset('images/logo.svg') }}" alt="Martingalian Logo" class="w-32 h-auto">
                    </div>
                    <p class="text-xs text-white/60 text-center">Pure black</p>
                </div>
            </div>
        </div>

        {{-- FAVICON PREVIEW --}}
        <div class="mb-16 p-8 rounded-2xl border border-blue-400/30 bg-blue-500/10">
            <h2 class="text-xl font-semibold text-blue-200 mb-6 text-center">Favicon Preview</h2>
            <p class="text-blue-200/70 text-sm text-center mb-8 max-w-2xl mx-auto">
                How the logo appears in browser tabs at actual size
            </p>

            <div class="flex flex-wrap justify-center items-center gap-8 p-8 rounded-2xl bg-[#0c0a0b]">
                <div class="flex items-center gap-3 p-3 rounded bg-white/5">
                    <img src="{{ asset('images/logo.svg') }}" alt="Favicon" class="w-4 h-auto">
                    <span class="text-sm text-white/80">Browser Tab (16x16)</span>
                </div>
                <div class="flex items-center gap-3 p-3 rounded bg-white/5">
                    <img src="{{ asset('images/logo.svg') }}" alt="Favicon" class="w-8 h-auto">
                    <span class="text-sm text-white/80">Bookmarks (32x32)</span>
                </div>
                <div class="flex items-center gap-3 p-3 rounded bg-white/5">
                    <img src="{{ asset('images/logo.svg') }}" alt="Favicon" class="w-12 h-auto">
                    <span class="text-sm text-white/80">Desktop Shortcut (48x48)</span>
                </div>
            </div>
        </div>

        {{-- USAGE RECOMMENDATIONS --}}
        <div class="p-8 rounded-2xl border border-emerald-400/30 bg-emerald-500/10">
            <h2 class="text-xl font-semibold text-emerald-200 mb-6 text-center">Usage Recommendations</h2>

            <div class="grid md:grid-cols-3 gap-6 max-w-4xl mx-auto">
                <div class="p-4 rounded-lg bg-black/20">
                    <h3 class="font-semibold text-emerald-200 mb-2">🌐 Web</h3>
                    <ul class="text-sm text-emerald-200/80 space-y-1">
                        <li>• Favicon (16x16, 32x32)</li>
                        <li>• Apple touch icon (180x180)</li>
                        <li>• Android icon (192x192, 512x512)</li>
                    </ul>
                </div>
                <div class="p-4 rounded-lg bg-black/20">
                    <h3 class="font-semibold text-emerald-200 mb-2">📱 Social Media</h3>
                    <ul class="text-sm text-emerald-200/80 space-y-1">
                        <li>• Profile picture (256x256)</li>
                        <li>• OpenGraph (1200x630)</li>
                        <li>• Twitter Card (1200x600)</li>
                    </ul>
                </div>
                <div class="p-4 rounded-lg bg-black/20">
                    <h3 class="font-semibold text-emerald-200 mb-2">💼 Branding</h3>
                    <ul class="text-sm text-emerald-200/80 space-y-1">
                        <li>• Email signatures (128x128)</li>
                        <li>• Documents (256x256+)</li>
                        <li>• Presentations (512x512)</li>
                    </ul>
                </div>
            </div>

            <div class="mt-8 p-4 rounded-lg bg-black/30 text-emerald-200 text-sm max-w-2xl mx-auto">
                <p class="font-semibold mb-2">✨ Key Features:</p>
                <ul class="list-disc list-inside space-y-1 text-emerald-200/80">
                    <li>Scalable vector format - infinite resolution, zero quality loss</li>
                    <li>Clean, crisp design that scales perfectly</li>
                    <li>White inner border ensures visibility on all surfaces</li>
                    <li>Red brand color with subtle gradient depth</li>
                    <li>Chart pattern clearly represents trading/financial focus</li>
                </ul>
            </div>
        </div>
    </div>
</x-layouts.app>
