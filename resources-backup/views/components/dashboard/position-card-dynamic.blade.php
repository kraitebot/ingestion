@php
    // Get theme-based colors using the helper
    $primaryColors = theme_map_color(theme('primary.base'));
    $secondaryColors = theme_map_color(theme('secondary.base'));
    $successColors = theme_map_color(theme('success.base'));
    $errorColors = theme_map_color(theme('error.base'));
@endphp

{{-- Dynamic Position Card Component (Alpine.js powered) --}}
<div class="rounded-2xl p-5 relative overflow-hidden backdrop-blur-2xl transition-all duration-300 hover:scale-[1.01] hover:shadow-2xl group position-card"
     style="background: linear-gradient(135deg, rgba(255,255,255,0.07) 0%, rgba(255,255,255,0.04) 100%); border: 1px solid rgba(255,255,255,0.12); box-shadow: 0 8px 32px rgba(0,0,0,0.3), inset 0 1px 0 rgba(255,255,255,0.15);">

    {{-- Dramatic glassmorphism overlay --}}
    <div class="absolute inset-0 rounded-2xl pointer-events-none opacity-40 group-hover:opacity-60 transition-opacity duration-500"
         style="background: radial-gradient(circle at 20% 20%, rgba(255,255,255,0.06) 0%, transparent 50%), linear-gradient(135deg, rgba(255,255,255,0.04) 0%, transparent 50%);"></div>

    {{-- Animated glow on hover --}}
    <div class="absolute inset-0 rounded-2xl pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity duration-500"
         style="box-shadow: inset 0 0 40px rgba(255,255,255,0.1), 0 0 40px rgba(255,255,255,0.05);"></div>

    {{-- Action Badges - animated based on API state --}}
    <span x-show="showHedgedBadge"
          x-transition:enter="transition ease-out duration-200"
          x-transition:enter-start="opacity-0 -translate-y-full"
          x-transition:enter-end="opacity-100 translate-y-0"
          x-transition:leave="transition ease-in duration-200"
          x-transition:leave-start="opacity-100 translate-y-0"
          x-transition:leave-end="opacity-0 -translate-y-full"
          class="absolute top-0 left-1/2 z-20 {{ $primaryColors['bg'] }} text-white text-[11px] font-bold tracking-wider px-4 py-1 rounded-b-lg shadow-md flex items-center gap-1.5"
          style="transform: translateX(-50%);">
        <x-feathericon-shield class="h-3 w-3" />
        HEDGED
    </span>

    <span x-show="showWapedBadge"
          x-transition:enter="transition ease-out duration-200"
          x-transition:enter-start="opacity-0 -translate-y-full"
          x-transition:enter-end="opacity-100 translate-y-0"
          x-transition:leave="transition ease-in duration-200"
          x-transition:leave-start="opacity-100 translate-y-0"
          x-transition:leave-end="opacity-0 -translate-y-full"
          class="absolute top-0 left-1/2 z-20 bg-orange-600 text-white text-[11px] font-bold tracking-wider px-4 py-1 rounded-b-lg shadow-md flex items-center gap-1.5"
          style="transform: translateX(-50%);">
        <x-feathericon-zap class="h-3 w-3" />
        WAP'ed
    </span>

    <span x-show="showRecentlyOpenedBadge"
          x-transition:enter="transition ease-out duration-200"
          x-transition:enter-start="opacity-0 -translate-y-full"
          x-transition:enter-end="opacity-100 translate-y-0"
          x-transition:leave="transition ease-in duration-200"
          x-transition:leave-start="opacity-100 translate-y-0"
          x-transition:leave-end="opacity-0 -translate-y-full"
          class="absolute top-0 left-1/2 z-20 {{ $secondaryColors['bg'] }} text-white text-[11px] font-bold tracking-wider px-4 py-1 rounded-b-lg shadow-md flex items-center gap-1.5"
          style="transform: translateX(-50%);">
        <x-feathericon-clock class="h-3 w-3" />
        Recently Opened
    </span>

    {{-- Content wrapper --}}
    <div class="relative z-10">
        {{-- Header --}}
        <div class="flex items-start justify-between mb-4">
            <div class="flex items-center gap-3">
                {{-- Token Icon --}}
                <img :src="data.icon_url" :alt="data.token" class="h-10 w-10 rounded-full" x-show="data.icon_url">
                <div x-show="!data.icon_url" class="h-10 w-10 rounded-full bg-white/10 flex items-center justify-center">
                    <span class="text-white font-bold text-sm" x-text="data.token?.charAt(0)"></span>
                </div>

                {{-- Token Info --}}
                <div>
                    <div class="flex items-center gap-1.5 mb-1 flex-wrap">
                        <span :class="data.position === 'short' ? '{{ $errorColors['text'] }}' : '{{ $successColors['text'] }}'"
                              class="text-xs font-bold uppercase tracking-wider drop-shadow whitespace-nowrap"
                              style="font-family: 'Space Grotesk', sans-serif;"
                              x-text="data.position"></span>
                        <span class="text-white/40 text-xs whitespace-nowrap" x-text="'· ' + data.opened_at_human"></span>
                    </div>
                    <h3 class="text-lg font-extrabold text-white drop-shadow-lg" style="font-family: 'Space Grotesk', sans-serif;">
                        <span x-text="data.token"></span>
                        <span class="text-white/60 text-sm font-mono" x-text="'(' + data.leverage + ')'"></span>
                    </h3>
                    <p class="text-white/70 text-sm font-light" style="font-family: 'Space Grotesk', sans-serif;" x-text="data.name"></p>
                </div>
            </div>

            {{-- Time Period Filters --}}
            <div class="flex gap-1.5">
                <button :class="data.timeframes['1d'] === 1 ? '{{ $successColors['bg'] }}/20 {{ $successColors['border'] }}/30 {{ $successColors['text'] }} {{ $successColors['hover'] }}/30' : '{{ $errorColors['bg'] }}/20 {{ $errorColors['border'] }}/30 {{ $errorColors['text'] }} {{ $errorColors['hover'] }}/30'"
                        class="h-7 w-7 rounded-full text-[10px] font-bold flex items-center justify-center">1d</button>
                <button :class="data.timeframes['4h'] === 1 ? '{{ $successColors['bg'] }}/20 {{ $successColors['border'] }}/30 {{ $successColors['text'] }} {{ $successColors['hover'] }}/30' : '{{ $errorColors['bg'] }}/20 {{ $errorColors['border'] }}/30 {{ $errorColors['text'] }} {{ $errorColors['hover'] }}/30'"
                        class="h-7 w-7 rounded-full text-[10px] font-bold flex items-center justify-center">4h</button>
            </div>
        </div>

        {{-- Price and Variation --}}
        <div class="flex items-end justify-between mb-3">
            <div>
                <p class="text-white/60 text-xs mb-0.5 uppercase tracking-wide font-medium" style="font-family: 'Space Grotesk', sans-serif;">Mark Price</p>
                <p class="text-xl font-bold text-white drop-shadow-lg font-mono"
                   style="font-family: 'JetBrains Mono', monospace; font-feature-settings: 'tnum', 'lnum';"
                   x-text="formatNumber(data.mark_price)"></p>
            </div>
            <div class="text-right">
                <p class="text-white/60 text-xs mb-0.5 uppercase tracking-wide font-medium" style="font-family: 'Space Grotesk', sans-serif;">Variation %</p>
                <p class="text-xl font-bold drop-shadow-lg font-mono"
                   :class="data.variation_percent < 0 ? '{{ $errorColors['text'] }}' : '{{ $successColors['text'] }}'"
                   style="font-family: 'JetBrains Mono', monospace; font-feature-settings: 'tnum', 'lnum';"
                   x-text="data.variation_percent + '%'"></p>
            </div>
        </div>

        {{-- Chart.js Chart --}}
        <div class="h-16 rounded-lg mb-4 relative overflow-visible">
            <canvas :id="'chart-' + data.position + '-' + data.token + '-' + data.id" class="w-full" style="height: 56px;"></canvas>

            {{-- HTML tooltip popup (positioned absolutely above canvas) --}}
            <div x-show="showChartTooltip"
                 x-transition:enter="transition ease-out duration-100"
                 x-transition:enter-start="opacity-0"
                 x-transition:enter-end="opacity-100"
                 x-transition:leave="transition ease-in duration-75"
                 x-transition:leave-start="opacity-100"
                 x-transition:leave-end="opacity-0"
                 class="absolute pointer-events-none z-50"
                 :style="`left: ${tooltipX}px; top: ${tooltipY - 20}px; transform: translate(-50%, -100%);`">
                <div class="relative">
                    <div class="rounded px-2 py-1 text-center shadow-lg"
                         :class="data.position === 'long' ? 'bg-black/90 border {{ $successColors['border'] }}' : 'bg-black/90 border {{ $errorColors['border'] }}'"
                         style="backdrop-filter: blur(8px);">
                        <div class="text-white text-xs font-bold font-mono" x-text="tooltipPrice"></div>
                        <div class="text-white/60 text-[9px] font-mono" x-text="tooltipTime"></div>
                    </div>
                    {{-- Down arrow tip --}}
                    <div class="absolute left-1/2 -translate-x-1/2 -bottom-[4px]">
                        <div class="w-0 h-0 border-l-[5px] border-l-transparent border-r-[5px] border-r-transparent"
                             :class="data.position === 'long' ? 'border-t-[5px] border-t-{{ str_replace('border-', '', $successColors['border']) }}' : 'border-t-[5px] border-t-{{ str_replace('border-', '', $errorColors['border']) }}'"></div>
                        <div class="w-0 h-0 border-l-[4px] border-l-transparent border-r-[4px] border-r-transparent absolute left-1/2 -translate-x-1/2 -top-[5px]"
                             :class="data.position === 'long' ? 'border-t-[4px] border-t-black/90' : 'border-t-[4px] border-t-black/90'"></div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Ladder Progress Bar --}}
        <div class="mb-6 mt-5">
            <div class="relative w-full">
                <div class="relative pt-5">
                    {{-- Limit order tick labels (1, 2, 3, 4) - only show if not filled --}}
                    <template x-for="(tickPrice, index) in data.ladder.tick_prices" :key="'label-' + index">
                        <span x-show="!isTickFilled(index)"
                              class="absolute top-0 -translate-x-1/2 -translate-y-[6px] text-[10px] text-slate-400 leading-none"
                              :style="`left: ${getTickPosition(index)}%`"
                              x-text="index + 1"></span>
                    </template>

                    {{-- Profit price marker label (P) --}}
                    <span x-show="shouldShowProfitMarker()"
                          class="absolute z-30 top-0 -translate-x-1/2 -translate-y-[6px] text-[10px] text-rose-300 leading-none font-semibold"
                          :style="`left: ${getProfitPosition()}%`">P</span>

                    {{-- Progress bar container --}}
                    <div class="relative">
                        {{-- Background bar --}}
                        <div class="h-1 rounded-full bg-slate-700/60"></div>

                        {{-- Green progress fill (mark price position) --}}
                        <div class="absolute top-0 left-0 h-1 bg-emerald-500/80 rounded-full"
                             :style="`width: ${getMarkPriceProgress()}%`"></div>

                        {{-- Tick marks --}}
                        <div class="absolute inset-0 pointer-events-none">
                            {{-- Start tick (opening price) --}}
                            <span class="absolute top-1/2 -translate-x-1/2 -translate-y-1/2" style="left:0%">
                                <span class="block w-[2px] h-[18px] bg-slate-400/70"></span>
                            </span>

                            {{-- Limit order ticks --}}
                            <template x-for="(tickPrice, index) in data.ladder.tick_prices" :key="'tick-' + index">
                                <span class="absolute top-1/2 -translate-x-1/2 -translate-y-1/2"
                                      :style="`left: ${getTickPosition(index)}%`">
                                    <span class="block w-[2px] h-[18px] bg-slate-400/70"></span>
                                </span>
                            </template>

                            {{-- Profit price marker tick (P) --}}
                            <span x-show="shouldShowProfitMarker()"
                                  class="absolute z-30 top-1/2 -translate-x-1/2 -translate-y-1/2"
                                  :style="`left: ${getProfitPosition()}%`">
                                <span class="block w-[2px] h-[18px] bg-rose-400"></span>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Stats Grid --}}
        <div class="grid grid-cols-3 gap-2 mb-3">
            <div class="rounded border border-white/10 bg-black/20 p-2">
                <p class="text-white/60 text-xs mb-0.5">Size</p>
                <p class="text-white text-sm font-semibold"
                   style="font-family: 'JetBrains Mono', monospace; font-feature-settings: 'tnum', 'lnum';"
                   x-text="formatNumber(data.stats.size)"></p>
            </div>
            <div class="rounded border border-white/10 bg-black/20 p-2">
                <p class="text-white/60 text-xs mb-0.5">Alpha Path</p>
                <p class="{{ $errorColors['text'] }} text-sm font-semibold"
                   style="font-family: 'JetBrains Mono', monospace; font-feature-settings: 'tnum', 'lnum';"
                   x-text="formatNumber(data.stats.alpha_path, 1)"></p>
            </div>
            <div class="rounded border border-white/10 bg-black/20 p-2">
                <p class="text-white/60 text-xs mb-0.5">Limit Filled</p>
                <p class="text-white text-sm font-semibold">
                    <span style="font-family: 'JetBrains Mono', monospace; font-feature-settings: 'tnum', 'lnum';" x-text="data.stats.limit_filled_count"></span>
                    <span class="{{ $successColors['text'] }}" style="font-family: 'JetBrains Mono', monospace; font-feature-settings: 'tnum', 'lnum';" x-text="' ' + data.stats.limit_filled_percent + '%'"></span>
                </p>
            </div>
        </div>

        {{-- Bottom Row Stats --}}
        <div class="grid grid-cols-3 gap-2 text-xs">
            <div>
                <p class="text-white/60 mb-0.5">Opening Price</p>
                <p class="text-white font-medium"
                   style="font-family: 'JetBrains Mono', monospace; font-feature-settings: 'tnum', 'lnum';"
                   x-text="formatNumber(data.prices.opening_price)"></p>
            </div>
            <div>
                <p class="text-white/60 mb-0.5">Profit Price</p>
                <p class="text-white font-medium"
                   style="font-family: 'JetBrains Mono', monospace; font-feature-settings: 'tnum', 'lnum';"
                   x-text="formatNumber(data.prices.profit_price)"></p>
            </div>
            <div>
                <p class="text-white/60 mb-0.5">Next Limit Order</p>
                <p class="text-white font-medium"
                   style="font-family: 'JetBrains Mono', monospace; font-feature-settings: 'tnum', 'lnum';"
                   x-text="data.prices.next_limit_order ? formatNumber(data.prices.next_limit_order) : '—'"></p>
            </div>
        </div>
    </div>

    <script>
        // Position card Alpine component
        function positionCard(positionData) {
            console.log('🎴 Position Card Init:', {
                token: positionData.token,
                id: positionData.id,
                position: positionData.position,
                fullData: positionData
            });

            return {
                data: positionData,
                chartInstance: null,
                showHedgedBadge: positionData.is_hedged || false,
                showWapedBadge: positionData.is_waped || false,
                showRecentlyOpenedBadge: positionData.is_recently_opened || false,
                showChartTooltip: false,
                tooltipX: 0,
                tooltipY: 0,
                tooltipPrice: '',
                tooltipTime: '',

                init() {
                    console.log('🔧 Position Card mounted:', this.data.token + '-' + this.data.id);

                    // Generate stable random offsets for tick positions (don't recalculate on each render)
                    this.tickRandomOffsets = (this.data.ladder.tick_prices || []).map(() => (Math.random() - 0.5) * 4);

                    // Debug ladder data
                    console.log('📊 Ladder data:', {
                        start_price: this.data.ladder.start_price,
                        end_price: this.data.ladder.end_price,
                        current_price: this.data.ladder.current_price,
                        profit_price: this.data.ladder.profit_price,
                        tick_prices: this.data.ladder.tick_prices,
                        shouldShowP: this.shouldShowProfitMarker()
                    });

                    // Initialize chart after component is ready
                    this.$nextTick(() => {
                        this.initChart();
                    });

                    // Clean up chart on component destroy
                    this.$watch('data', () => {
                        // Re-initialize chart when data changes
                        this.destroyChart();
                        this.$nextTick(() => {
                            this.initChart();
                        });
                    });

                    // Watch for badge state changes
                    this.$watch('data.is_hedged', (newVal) => {
                        this.showHedgedBadge = newVal;
                    });
                    this.$watch('data.is_waped', (newVal) => {
                        this.showWapedBadge = newVal;
                    });
                    this.$watch('data.is_recently_opened', (newVal) => {
                        this.showRecentlyOpenedBadge = newVal;
                    });
                },

                initChart() {
                    // Don't re-initialize if chart already exists
                    if (this.chartInstance) {
                        console.log('⏭️ Skipping chart init (already exists):', this.data.token);
                        return;
                    }

                    const canvasId = `chart-${this.data.position}-${this.data.token}-${this.data.id}`;
                    console.log('📊 Initializing chart:', {
                        canvasId,
                        chartDataPresent: !!this.data.chart,
                        chartDataTicks: this.data.chart?.length
                    });

                    if (typeof window.initChart === 'function') {
                        // Pass tooltip callbacks to chart
                        const callbacks = {
                            onTooltipShow: (x, y, price, time) => {
                                this.tooltipX = x;
                                this.tooltipY = y;
                                this.tooltipPrice = price;
                                this.tooltipTime = time;
                                this.showChartTooltip = true;
                            },
                            onTooltipHide: () => {
                                this.showChartTooltip = false;
                            }
                        };

                        this.chartInstance = window.initChart(canvasId, this.data.chart, this.data.position, callbacks);
                        console.log('✅ Chart initialized:', canvasId);
                    } else {
                        console.error('❌ window.initChart not available');
                    }
                },

                destroyChart() {
                    if (this.chartInstance) {
                        this.chartInstance.destroy();
                        this.chartInstance = null;
                    }
                },

                formatNumber(value, decimals = 2) {
                    if (value === null || value === undefined) return '—';
                    return new Intl.NumberFormat('en-US', {
                        minimumFractionDigits: decimals,
                        maximumFractionDigits: decimals
                    }).format(value);
                },

                /**
                 * Get position percentage for a tick (limit order) on the ladder
                 * Based on actual tick price position with slight randomization
                 */
                getTickPosition(tickIndex) {
                    const { start_price, end_price, tick_prices } = this.data.ladder;
                    const tickPrice = tick_prices[tickIndex];
                    const priceRange = end_price - start_price;

                    if (priceRange === 0) {
                        // Fallback to even distribution
                        const totalTicks = tick_prices.length;
                        return ((tickIndex + 1) / totalTicks) * 100;
                    }

                    // Calculate position based on price
                    const priceProgress = tickPrice - start_price;
                    const basePercentage = (priceProgress / priceRange) * 100;

                    // Add stable random offset (±2%) for visual variety
                    const randomOffset = this.tickRandomOffsets[tickIndex] || 0;
                    const percentage = basePercentage + randomOffset;

                    return Math.min(98, Math.max(2, percentage));
                },

                /**
                 * Check if a tick (limit order) has been filled
                 * Mark price has passed this tick, so number disappears
                 */
                isTickFilled(tickIndex) {
                    const { start_price, end_price, tick_prices, current_price } = this.data.ladder;
                    const tickPrice = tick_prices[tickIndex];

                    // For LONG: start_price > end_price (going down)
                    // Tick is filled when current_price <= tickPrice
                    if (start_price > end_price) {
                        return current_price <= tickPrice;
                    }

                    // For SHORT: start_price < end_price (going up)
                    // Tick is filled when current_price >= tickPrice
                    return current_price >= tickPrice;
                },

                /**
                 * Calculate green bar progress (mark price position on ladder)
                 * Always shows at least 5%, max ~85%
                 */
                getMarkPriceProgress() {
                    const { start_price, end_price, current_price } = this.data.ladder;
                    const priceRange = end_price - start_price;

                    if (priceRange === 0) return 10; // Default minimum

                    const priceProgress = current_price - start_price;
                    const percentage = (priceProgress / priceRange) * 100;

                    // Always show at least 5% progress, cap at 85% (never reaches end)
                    return Math.min(85, Math.max(5, percentage));
                },

                /**
                 * Get profit price marker (P) position percentage
                 */
                getProfitPosition() {
                    const { start_price, end_price, profit_price } = this.data.ladder;
                    const priceRange = end_price - start_price;

                    if (priceRange === 0) return 50;

                    const profitProgress = profit_price - start_price;
                    const percentage = (profitProgress / priceRange) * 100;

                    return Math.min(100, Math.max(0, percentage));
                },

                /**
                 * Check if profit marker should be shown
                 * Only show if profit_price is within ladder range AND the green bar has passed it
                 * P should always be LESS than (to the left of) the green bar
                 */
                shouldShowProfitMarker() {
                    const { start_price, end_price, profit_price, current_price } = this.data.ladder;
                    if (!profit_price) return false;

                    const min = Math.min(start_price, end_price);
                    const max = Math.max(start_price, end_price);

                    // Check if profit price is within ladder range
                    if (profit_price < min || profit_price > max) return false;

                    // For LONG: start_price > end_price (going down)
                    // Show P only if current_price has passed it (current <= profit)
                    if (start_price > end_price) {
                        return current_price <= profit_price;
                    }

                    // For SHORT: start_price < end_price (going up)
                    // Show P only if current_price has passed it (current >= profit)
                    return current_price >= profit_price;
                }
            };
        }

        // Make it globally available
        window.positionCard = positionCard;
    </script>
</div>
