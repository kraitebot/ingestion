{{-- resources/views/account-analytics.blade.php --}}
<x-layouts.app
    title="Account Analytics — Martingalian"
    meta-description="Detailed trading analytics for your account"
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
            :show-login="false"
            :show-subscribe="false"
            :show-logout="true"
        />
    </x-slot:navbar>

    <section class="px-4 py-8">
        <div class="mx-auto max-w-6xl">
            {{-- Page Header --}}
            <div class="mb-6 flex items-center justify-between">
                <div>
                    <h1 class="text-2xl md:text-3xl font-bold text-white">Account Analytics</h1>
                    <p class="text-sm text-white/60 mt-1">Main Binance Account — Detailed Performance</p>
                </div>
                <a
                    href="{{ route('home') }}"
                    class="inline-flex items-center gap-2 text-sm text-white/60 hover:text-red-400 transition-colors"
                >
                    <x-feathericon-arrow-left class="h-4 w-4" aria-hidden="true"/>
                    Back to Dashboard
                </a>
            </div>

            {{-- Analytics Container --}}
            <div class="rounded-2xl border border-white/10 bg-gradient-to-br from-white/[0.07] to-white/[0.02] overflow-hidden">
                {{-- Summary Section (always visible) --}}
                <div class="px-6 md:px-8 py-6 md:py-8 border-b border-white/10">
                    <h2 class="text-lg font-semibold text-white mb-6">Month Summary</h2>

                    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                        {{-- Total Profit --}}
                        <div class="rounded-lg bg-white/[0.03] border border-white/5 p-4">
                            <p class="text-xs text-white/50 mb-2 flex items-center gap-1.5">
                                <x-feathericon-trending-up class="h-3 w-3" aria-hidden="true"/>
                                Total Profit
                            </p>
                            <p class="text-2xl font-bold text-emerald-300">+2.34%</p>
                            <p class="text-xs text-white/40 mt-1">$234.50</p>
                        </div>

                        {{-- Best Day --}}
                        <div class="rounded-lg bg-white/[0.03] border border-white/5 p-4">
                            <p class="text-xs text-white/50 mb-2 flex items-center gap-1.5">
                                <x-feathericon-star class="h-3 w-3" aria-hidden="true"/>
                                Best Day
                            </p>
                            <p class="text-2xl font-bold text-emerald-300">+0.45%</p>
                            <p class="text-xs text-white/40 mt-1">Nov 15, 2024</p>
                        </div>

                        {{-- Worst Day --}}
                        <div class="rounded-lg bg-white/[0.03] border border-white/5 p-4">
                            <p class="text-xs text-white/50 mb-2 flex items-center gap-1.5">
                                <x-feathericon-alert-circle class="h-3 w-3" aria-hidden="true"/>
                                Worst Day
                            </p>
                            <p class="text-2xl font-bold text-red-300">-0.18%</p>
                            <p class="text-xs text-white/40 mt-1">Nov 8, 2024</p>
                        </div>

                        {{-- Win Rate --}}
                        <div class="rounded-lg bg-white/[0.03] border border-white/5 p-4">
                            <p class="text-xs text-white/50 mb-2 flex items-center gap-1.5">
                                <x-feathericon-target class="h-3 w-3" aria-hidden="true"/>
                                Win Rate
                            </p>
                            <p class="text-2xl font-bold text-white">72.5%</p>
                            <p class="text-xs text-white/40 mt-1">29/40 trades</p>
                        </div>
                    </div>
                </div>

                {{-- Monthly Data Container (scrollable with slide navigation) --}}
                <div class="relative h-[500px] sm:h-[600px] overflow-hidden">
                    {{-- November 2024 --}}
                    <div data-month="2024-11" class="month-entry absolute top-0 left-0 w-full h-full transition-transform duration-500 ease-out">
                        <div class="h-full overflow-y-auto px-6 md:px-8 py-6 md:py-8">
                            <h3 class="text-base font-semibold text-white mb-4">November 2024 — Daily Breakdown</h3>

                            {{-- Daily Table --}}
                            <div class="overflow-x-auto">
                                <table class="w-full text-xs sm:text-sm min-w-[600px]">
                                    <thead>
                                        <tr class="border-b border-white/10">
                                            <th class="text-left py-2 sm:py-3 px-2 sm:px-4 text-white/60 font-medium">Date</th>
                                            <th class="text-left py-2 sm:py-3 px-2 sm:px-4 text-white/60 font-medium">Trades</th>
                                            <th class="text-right py-2 sm:py-3 px-2 sm:px-4 text-white/60 font-medium">Profit/Loss</th>
                                            <th class="text-right py-2 sm:py-3 px-2 sm:px-4 text-white/60 font-medium">Win Rate</th>
                                            <th class="text-right py-2 sm:py-3 px-2 sm:px-4 text-white/60 font-medium">Balance</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @php
                                            $days = [
                                                ['date' => 'Nov 1', 'trades' => 2, 'profit' => '+0.12%', 'profit_amount' => '+$12.00', 'winrate' => '100%', 'balance' => '$10,012.00', 'positive' => true],
                                                ['date' => 'Nov 2', 'trades' => 3, 'profit' => '+0.08%', 'profit_amount' => '+$8.00', 'winrate' => '66.7%', 'balance' => '$10,020.00', 'positive' => true],
                                                ['date' => 'Nov 3', 'trades' => 0, 'profit' => '—', 'profit_amount' => '—', 'winrate' => '—', 'balance' => '$10,020.00', 'positive' => null],
                                                ['date' => 'Nov 4', 'trades' => 4, 'profit' => '+0.22%', 'profit_amount' => '+$22.00', 'winrate' => '75%', 'balance' => '$10,042.00', 'positive' => true],
                                                ['date' => 'Nov 5', 'trades' => 2, 'profit' => '-0.05%', 'profit_amount' => '-$5.00', 'winrate' => '50%', 'balance' => '$10,037.00', 'positive' => false],
                                                ['date' => 'Nov 6', 'trades' => 3, 'profit' => '+0.18%', 'profit_amount' => '+$18.00', 'winrate' => '100%', 'balance' => '$10,055.00', 'positive' => true],
                                                ['date' => 'Nov 7', 'trades' => 5, 'profit' => '+0.31%', 'profit_amount' => '+$31.00', 'winrate' => '80%', 'balance' => '$10,086.00', 'positive' => true],
                                            ];
                                        @endphp

                                        @foreach($days as $day)
                                            <tr class="border-b border-white/5 hover:bg-white/[0.02] transition-colors">
                                                <td class="py-2 sm:py-3 px-2 sm:px-4 text-white">{{ $day['date'] }}</td>
                                                <td class="py-2 sm:py-3 px-2 sm:px-4 text-white/70">{{ $day['trades'] }}</td>
                                                <td class="py-2 sm:py-3 px-2 sm:px-4 text-right">
                                                    @if($day['positive'] === true)
                                                        <span class="text-emerald-300 font-medium">{{ $day['profit'] }}</span>
                                                        <span class="text-emerald-300/60 text-xs ml-1">{{ $day['profit_amount'] }}</span>
                                                    @elseif($day['positive'] === false)
                                                        <span class="text-red-300 font-medium">{{ $day['profit'] }}</span>
                                                        <span class="text-red-300/60 text-xs ml-1">{{ $day['profit_amount'] }}</span>
                                                    @else
                                                        <span class="text-white/40">{{ $day['profit'] }}</span>
                                                    @endif
                                                </td>
                                                <td class="py-2 sm:py-3 px-2 sm:px-4 text-right text-white/70">{{ $day['winrate'] }}</td>
                                                <td class="py-2 sm:py-3 px-2 sm:px-4 text-right text-white font-medium">{{ $day['balance'] }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    {{-- October 2024 --}}
                    <div data-month="2024-10" class="month-entry absolute top-0 left-0 w-full h-full transition-transform duration-500 ease-out pointer-events-none">
                        <div class="h-full overflow-y-auto px-6 md:px-8 py-6 md:py-8">
                            <h3 class="text-base font-semibold text-white mb-4">October 2024 — Daily Breakdown</h3>

                            {{-- Daily Table --}}
                            <div class="overflow-x-auto">
                                <table class="w-full text-xs sm:text-sm min-w-[600px]">
                                    <thead>
                                        <tr class="border-b border-white/10">
                                            <th class="text-left py-2 sm:py-3 px-2 sm:px-4 text-white/60 font-medium">Date</th>
                                            <th class="text-left py-2 sm:py-3 px-2 sm:px-4 text-white/60 font-medium">Trades</th>
                                            <th class="text-right py-2 sm:py-3 px-2 sm:px-4 text-white/60 font-medium">Profit/Loss</th>
                                            <th class="text-right py-2 sm:py-3 px-2 sm:px-4 text-white/60 font-medium">Win Rate</th>
                                            <th class="text-right py-2 sm:py-3 px-2 sm:px-4 text-white/60 font-medium">Balance</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @php
                                            $octoberDays = [
                                                ['date' => 'Oct 1', 'trades' => 3, 'profit' => '+0.15%', 'profit_amount' => '+$15.00', 'winrate' => '66.7%', 'balance' => '$10,015.00', 'positive' => true],
                                                ['date' => 'Oct 2', 'trades' => 2, 'profit' => '+0.09%', 'profit_amount' => '+$9.00', 'winrate' => '100%', 'balance' => '$10,024.00', 'positive' => true],
                                                ['date' => 'Oct 3', 'trades' => 4, 'profit' => '-0.12%', 'profit_amount' => '-$12.00', 'winrate' => '25%', 'balance' => '$10,012.00', 'positive' => false],
                                                ['date' => 'Oct 4', 'trades' => 1, 'profit' => '+0.06%', 'profit_amount' => '+$6.00', 'winrate' => '100%', 'balance' => '$10,018.00', 'positive' => true],
                                                ['date' => 'Oct 5', 'trades' => 0, 'profit' => '—', 'profit_amount' => '—', 'winrate' => '—', 'balance' => '$10,018.00', 'positive' => null],
                                            ];
                                        @endphp

                                        @foreach($octoberDays as $day)
                                            <tr class="border-b border-white/5 hover:bg-white/[0.02] transition-colors">
                                                <td class="py-2 sm:py-3 px-2 sm:px-4 text-white">{{ $day['date'] }}</td>
                                                <td class="py-2 sm:py-3 px-2 sm:px-4 text-white/70">{{ $day['trades'] }}</td>
                                                <td class="py-2 sm:py-3 px-2 sm:px-4 text-right">
                                                    @if($day['positive'] === true)
                                                        <span class="text-emerald-300 font-medium">{{ $day['profit'] }}</span>
                                                        <span class="text-emerald-300/60 text-xs ml-1">{{ $day['profit_amount'] }}</span>
                                                    @elseif($day['positive'] === false)
                                                        <span class="text-red-300 font-medium">{{ $day['profit'] }}</span>
                                                        <span class="text-red-300/60 text-xs ml-1">{{ $day['profit_amount'] }}</span>
                                                    @else
                                                        <span class="text-white/40">{{ $day['profit'] }}</span>
                                                    @endif
                                                </td>
                                                <td class="py-2 sm:py-3 px-2 sm:px-4 text-right text-white/70">{{ $day['winrate'] }}</td>
                                                <td class="py-2 sm:py-3 px-2 sm:px-4 text-right text-white font-medium">{{ $day['balance'] }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Fixed Month Navigation (always visible) --}}
                <div class="border-t border-white/10 px-4 md:px-6 py-3 md:py-4 flex justify-between items-center gap-2">
                    <button
                        type="button"
                        data-nav="next"
                        class="inline-flex items-center gap-1.5 md:gap-2 text-xs sm:text-sm text-white/60 hover:text-red-400 transition-colors cursor-pointer disabled:opacity-30 disabled:cursor-not-allowed min-h-[44px] px-2"
                    >
                        <x-feathericon-chevron-up class="h-4 w-4" aria-hidden="true"/>
                        <span class="hidden xs:inline">Next Month</span>
                        <span class="xs:hidden">Next</span>
                    </button>

                    <button
                        type="button"
                        data-nav="previous"
                        class="inline-flex items-center gap-1.5 md:gap-2 text-xs sm:text-sm text-white/60 hover:text-red-400 transition-colors cursor-pointer disabled:opacity-30 disabled:cursor-not-allowed min-h-[44px] px-2"
                    >
                        <span class="hidden xs:inline">Previous Month</span>
                        <span class="xs:hidden">Previous</span>
                        <x-feathericon-chevron-down class="h-4 w-4" aria-hidden="true"/>
                    </button>
                </div>
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

                // Month navigation (vertical slide - same as changelog)
                const months = document.querySelectorAll('.month-entry');
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
                        prevButton.disabled = currentIndex === months.length - 1;
                    }
                }

                // Initialize all months with correct positions
                months.forEach(function(month, index) {
                    // Disable transitions temporarily for initial positioning
                    month.style.transition = 'none';

                    if (index === 0) {
                        month.style.transform = 'translateY(0)';
                        month.classList.remove('pointer-events-none');
                        month.classList.add('pointer-events-auto');
                    } else {
                        month.style.transform = 'translateY(100%)';
                        month.classList.add('pointer-events-none');
                        month.classList.remove('pointer-events-auto');
                    }
                });

                // Force reflow to apply positions
                if (months.length > 0) {
                    months[0].offsetHeight;
                }

                // Re-enable transitions
                months.forEach(function(month) {
                    month.style.transition = '';
                });

                updateButtons();

                // Handle navigation buttons
                document.addEventListener('click', function(e) {
                    const navButton = e.target.closest('[data-nav]');
                    if (!navButton || isAnimating || navButton.disabled) return;

                    const direction = navButton.getAttribute('data-nav');
                    let nextIndex = currentIndex;

                    if (direction === 'previous' && currentIndex < months.length - 1) {
                        nextIndex = currentIndex + 1;
                    } else if (direction === 'next' && currentIndex > 0) {
                        nextIndex = currentIndex - 1;
                    }

                    if (nextIndex !== currentIndex) {
                        isAnimating = true;

                        const currentMonth = months[currentIndex];
                        const nextMonth = months[nextIndex];

                        // Enable next month for animation and bring to front
                        nextMonth.classList.add('pointer-events-auto');
                        currentMonth.style.zIndex = '1';
                        nextMonth.style.zIndex = '2';

                        if (direction === 'previous') {
                            // Going to older month: current slides up, next comes from below
                            currentMonth.style.transform = 'translateY(-100%)';
                            nextMonth.style.transform = 'translateY(0)';
                        } else {
                            // Going to newer month: current slides down, next comes from above
                            currentMonth.style.transform = 'translateY(100%)';
                            nextMonth.style.transform = 'translateY(0)';
                        }

                        setTimeout(function() {
                            // Disable interaction on old month and reset position
                            currentMonth.classList.add('pointer-events-none');
                            if (direction === 'previous') {
                                currentMonth.style.transform = 'translateY(-100%)';
                            } else {
                                currentMonth.style.transform = 'translateY(100%)';
                            }

                            // Scroll to top of content
                            const scrollableContent = nextMonth.querySelector('.overflow-y-auto');
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
