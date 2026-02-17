{{-- Position Ladder Progress Bar Component --}}
@props([
    'progress' => 0,  // 0-100 percentage
    'limitFilled' => 0,
    'totalLimits' => 4,
    'showP' => true,  // Show profit price marker
    'pPct' => null,   // Profit price percentage (0-100)
])

@php
    // Calculate tick positions (evenly spaced)
    $ticks = [];
    $step = 100 / $totalLimits;
    for ($i = 0; $i < $totalLimits; $i++) {
        $ticks[] = [
            'label' => $i + 1,
            'pct' => ($i + 1) * $step,
            'filled' => ($i + 1) <= $limitFilled
        ];
    }
@endphp

<div class="relative w-full">
    <div class="relative pt-5">
        {{-- Tick labels --}}
        @foreach($ticks as $tick)
            @if(!$tick['filled'])
                <span class="absolute top-0 -translate-x-1/2 -translate-y-[6px] text-[10px] text-slate-400 leading-none"
                      style="left:{{ $tick['pct'] }}%">{{ $tick['label'] }}</span>
            @endif
        @endforeach
        <span class="absolute top-0 -translate-x-1/2 -translate-y-[6px] text-[10px] text-slate-400 leading-none"
              style="left:100%">4</span>

        {{-- Profit price marker label --}}
        @if($showP && $pPct !== null)
            <span class="absolute z-30 top-0 -translate-x-1/2 -translate-y-[6px] text-[10px] text-rose-300 leading-none font-semibold"
                  style="left:{{ $pPct }}%">P</span>
        @endif

        {{-- Progress bar container --}}
        <div class="relative">
            {{-- Background bar --}}
            <div class="h-1 rounded-full bg-slate-700/60"></div>

            {{-- Progress fill --}}
            <div class="absolute top-0 left-0 h-1 bg-emerald-500/80 rounded-full"
                 style="width:{{ min(100, max(0, $progress)) }}%"></div>

            {{-- Tick marks --}}
            <div class="absolute inset-0 pointer-events-none">
                {{-- Start tick --}}
                <span class="absolute top-1/2 -translate-x-1/2 -translate-y-1/2" style="left:0%">
                    <span class="block w-[2px] h-[18px] bg-slate-400/70"></span>
                </span>

                {{-- Limit ticks --}}
                @foreach($ticks as $tick)
                    <span class="absolute top-1/2 -translate-x-1/2 -translate-y-1/2" style="left:{{ $tick['pct'] }}%">
                        <span class="block w-[2px] h-[18px] bg-slate-400/70"></span>
                    </span>
                @endforeach

                {{-- End tick --}}
                <span class="absolute top-1/2 -translate-x-1/2 -translate-y-1/2" style="left:100%">
                    <span class="block w-[2px] h-[18px] bg-slate-400/70"></span>
                </span>

                {{-- Profit price marker tick --}}
                @if($showP && $pPct !== null)
                    <span class="absolute z-30 top-1/2 -translate-x-1/2 -translate-y-1/2" style="left:{{ $pPct }}%">
                        <span class="block w-[2px] h-[18px] bg-rose-400"></span>
                    </span>
                @endif
            </div>
        </div>
    </div>
</div>
