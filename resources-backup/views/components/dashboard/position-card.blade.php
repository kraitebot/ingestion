{{-- Position Card Component --}}
@props([
    'token' => 'BTC',
    'name' => 'Bitcoin',
    'iconUrl' => null,
    'price' => '0.00',
    'variation' => '0.00',
    'position' => 'LONG',
    'leverage' => '20x',
    'timeAgo' => '1w ago',
    'badge' => '',
])

<div class="rounded-2xl p-5 relative overflow-hidden backdrop-blur-2xl transition-all duration-300 hover:scale-[1.01] hover:shadow-2xl group position-card" style="background: linear-gradient(135deg, rgba(255,255,255,0.07) 0%, rgba(255,255,255,0.04) 100%); border: 1px solid rgba(255,255,255,0.12); box-shadow: 0 8px 32px rgba(0,0,0,0.3), inset 0 1px 0 rgba(255,255,255,0.15);">
    {{-- Dramatic glassmorphism overlay --}}
    <div class="absolute inset-0 rounded-2xl pointer-events-none opacity-40 group-hover:opacity-60 transition-opacity duration-500" style="background: radial-gradient(circle at 20% 20%, rgba(255,255,255,0.06) 0%, transparent 50%), linear-gradient(135deg, rgba(255,255,255,0.04) 0%, transparent 50%);"></div>

    {{-- Animated glow on hover --}}
    <div class="absolute inset-0 rounded-2xl pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity duration-500" style="box-shadow: inset 0 0 40px rgba(255,255,255,0.1), 0 0 40px rgba(255,255,255,0.05);"></div>

    {{-- Action Badge - inside card, glued to top --}}
    @if($badge === 'HEDGED')
        <span class="absolute top-0 left-1/2 z-20 bg-rose-600 text-white text-[11px] font-bold tracking-wider px-4 py-1 rounded-b-lg shadow-md flex items-center gap-1.5 animate-badge-slide" style="transform: translate(-50%, -1px);">
            <x-feathericon-shield class="h-3 w-3" />
            HEDGED
        </span>
    @elseif($badge === 'WAP\'ed')
        <span class="absolute top-0 left-1/2 z-20 bg-orange-500 text-white text-[11px] font-bold tracking-wider px-4 py-1 rounded-b-lg shadow-md flex items-center gap-1.5 animate-badge-slide" style="transform: translate(-50%, -1px);">
            <x-feathericon-zap class="h-3 w-3" />
            WAP'ed
        </span>
    @elseif($badge === 'Recently Opened')
        <span class="absolute top-0 left-1/2 z-20 bg-blue-600 text-white text-[11px] font-bold tracking-wider px-4 py-1 rounded-b-lg shadow-md flex items-center gap-1.5 animate-badge-slide" style="transform: translate(-50%, -1px);">
            <x-feathericon-clock class="h-3 w-3" />
            Recently Opened
        </span>
    @endif

    {{-- Content wrapper --}}
    <div class="relative z-10">
    {{-- Header --}}
    <div class="flex items-start justify-between mb-4">
        <div class="flex items-center gap-3">
            {{-- Token Icon --}}
            @if($iconUrl)
                <img src="{{ $iconUrl }}" alt="{{ $token }}" class="h-10 w-10 rounded-full">
            @else
                <div class="h-10 w-10 rounded-full bg-white/10 flex items-center justify-center">
                    <span class="text-white font-bold text-sm">{{ substr($token, 0, 1) }}</span>
                </div>
            @endif

            {{-- Token Info --}}
            <div>
                <div class="flex items-center gap-1.5 mb-1 flex-wrap">
                    <span class="{{ strtoupper($position) === 'SHORT' ? 'text-red-400' : 'text-emerald-400' }} text-xs font-bold uppercase tracking-wider drop-shadow whitespace-nowrap" style="font-family: 'Space Grotesk', sans-serif;">{{ $position }}</span>
                    <span class="text-white/40 text-xs whitespace-nowrap">· {{ $timeAgo }}</span>
                </div>
                <h3 class="text-lg font-extrabold text-white drop-shadow-lg" style="font-family: 'Space Grotesk', sans-serif;">{{ $token }} <span class="text-white/60 text-sm font-mono">({{ $leverage }})</span></h3>
                <p class="text-white/70 text-sm font-light" style="font-family: 'Space Grotesk', sans-serif;">{{ $name }}</p>
            </div>
        </div>

        {{-- Time Period Filters --}}
        <div class="flex gap-1.5">
            @php
                $timeframes = [
                    ['label' => '1d', 'color' => rand(0, 1) ? 'emerald' : 'rose'],
                    ['label' => '12h', 'color' => rand(0, 1) ? 'emerald' : 'rose'],
                    ['label' => '4h', 'color' => rand(0, 1) ? 'emerald' : 'rose'],
                    ['label' => '1h', 'color' => rand(0, 1) ? 'emerald' : 'rose'],
                ];
            @endphp
            @foreach($timeframes as $tf)
                @php
                    $classes = $tf['color'] === 'emerald'
                        ? 'bg-emerald-500/20 border-emerald-400/30 text-emerald-300 hover:bg-emerald-500/30'
                        : 'bg-rose-500/20 border-rose-400/30 text-rose-300 hover:bg-rose-500/30';
                @endphp
                <button class="h-7 w-7 rounded-full {{ $classes }} text-[10px] font-bold flex items-center justify-center">{{ $tf['label'] }}</button>
            @endforeach
        </div>
    </div>

    {{-- Price and Variation --}}
    <div class="flex items-end justify-between mb-3">
        <div>
            <p class="text-white/60 text-xs mb-0.5 uppercase tracking-wide font-medium" style="font-family: 'Space Grotesk', sans-serif;">Mark Price</p>
            <p class="text-xl font-bold text-white drop-shadow-lg font-mono" style="font-family: 'JetBrains Mono', monospace; font-feature-settings: 'tnum', 'lnum';">{{ $price }}</p>
        </div>
        <div class="text-right">
            <p class="text-white/60 text-xs mb-0.5 uppercase tracking-wide font-medium" style="font-family: 'Space Grotesk', sans-serif;">Variation %</p>
            <p class="text-xl font-bold drop-shadow-lg font-mono {{ floatval($variation) < 0 ? 'text-red-400' : 'text-emerald-400' }}" style="font-family: 'JetBrains Mono', monospace; font-feature-settings: 'tnum', 'lnum';">{{ $variation }}%</p>
        </div>
    </div>

    {{-- Chart.js Chart --}}
    <div class="h-16 rounded-lg mb-4 relative overflow-hidden">
        <canvas id="chart-{{ $token }}-{{ rand() }}" class="w-full" style="height: 56px;"></canvas>
    </div>

    {{-- Ladder Progress Bar --}}
    <div class="mb-6 mt-5">
        @php
            $progress = rand(20, 80);
            $pPct = rand(10, max(10, $progress - 5)); // P is always before current progress
        @endphp
        <x-dashboard.position-ladder
            :progress="$progress"
            :limitFilled="rand(0, 3)"
            :totalLimits="4"
            :showP="true"
            :pPct="$pPct"
        />
    </div>

    {{-- Stats Grid --}}
    <div class="grid grid-cols-3 gap-2 mb-3">
        <div class="rounded border border-white/10 bg-black/20 p-2">
            <p class="text-white/60 text-xs mb-0.5">Size</p>
            <p class="text-white text-sm font-semibold" style="font-family: 'JetBrains Mono', monospace; font-feature-settings: 'tnum', 'lnum';">38,283.27</p>
        </div>
        <div class="rounded border border-white/10 bg-black/20 p-2">
            <p class="text-white/60 text-xs mb-0.5">Alpha Path</p>
            <p class="text-red-400 text-sm font-semibold" style="font-family: 'JetBrains Mono', monospace; font-feature-settings: 'tnum', 'lnum';">100.0</p>
        </div>
        <div class="rounded border border-white/10 bg-black/20 p-2">
            <p class="text-white/60 text-xs mb-0.5">Limit Filled</p>
            <p class="text-white text-sm font-semibold"><span style="font-family: 'JetBrains Mono', monospace; font-feature-settings: 'tnum', 'lnum';">4</span> <span class="text-emerald-400" style="font-family: 'JetBrains Mono', monospace; font-feature-settings: 'tnum', 'lnum';">0.0%</span></p>
        </div>
    </div>

    {{-- Bottom Row Stats --}}
    <div class="grid grid-cols-3 gap-2 text-xs">
        <div>
            <p class="text-white/60 mb-0.5">Opening Price</p>
            <p class="text-white font-medium" style="font-family: 'JetBrains Mono', monospace; font-feature-settings: 'tnum', 'lnum';">109.87</p>
        </div>
        <div>
            <p class="text-white/60 mb-0.5">Profit Price</p>
            <p class="text-white font-medium" style="font-family: 'JetBrains Mono', monospace; font-feature-settings: 'tnum', 'lnum';">78.98</p>
        </div>
        <div>
            <p class="text-white/60 mb-0.5">Next Limit Order</p>
            <p class="text-white font-medium" style="font-family: 'JetBrains Mono', monospace; font-feature-settings: 'tnum', 'lnum';">—</p>
        </div>
    </div>
    </div>
</div>
