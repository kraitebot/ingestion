{{-- resources/views/billing.blade.php --}}
<x-layouts.app
    title="Billing & Payments — Martingalian"
    meta-description="Manage your subscription and billing information"
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
                    <h1 class="text-2xl md:text-3xl font-bold text-white">Billing & Payments</h1>
                    <p class="text-sm text-white/60 mt-1">Manage your subscription and payment methods</p>
                </div>
                <a
                    href="{{ route('home') }}"
                    class="inline-flex items-center gap-2 text-sm text-white/60 hover:text-red-400 transition-colors"
                >
                    <x-feathericon-arrow-left class="h-4 w-4" aria-hidden="true"/>
                    Back to Dashboard
                </a>
            </div>

            <div class="space-y-6">
                {{-- Current Subscription Card --}}
                <div class="rounded-2xl border border-white/10 bg-gradient-to-br from-white/[0.07] to-white/[0.02] p-6 md:p-8">
                    <div class="flex items-start justify-between mb-6">
                        <div>
                            <h2 class="text-lg font-semibold text-white mb-2">Current Subscription</h2>
                            <p class="text-sm text-white/60">Your active plan and billing details</p>
                        </div>
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-400/10 border border-emerald-400/30 px-3 py-1 text-xs font-medium text-emerald-300">
                            <x-feathericon-check-circle class="h-3 w-3" aria-hidden="true"/>
                            Active
                        </span>
                    </div>

                    <div class="grid md:grid-cols-2 gap-6">
                        {{-- Plan Details --}}
                        <div class="space-y-4">
                            <div>
                                <p class="text-xs text-white/50 mb-1">Plan</p>
                                <p class="text-xl font-bold text-white">Pro Plan</p>
                            </div>
                            <div>
                                <p class="text-xs text-white/50 mb-1">Next Billing Date</p>
                                <p class="text-base text-white">December 7, 2024</p>
                            </div>
                            <div>
                                <p class="text-xs text-white/50 mb-1">Amount</p>
                                <p class="text-base text-white font-medium">$49.99 / month</p>
                                <p class="text-xs text-white/40 mt-1">≈ 0.0012 BTC</p>
                            </div>
                        </div>

                        {{-- Payment Method --}}
                        <div class="rounded-lg bg-white/[0.03] border border-white/5 p-4">
                            <div class="flex items-center gap-3 mb-4">
                                <div class="rounded-full bg-blue-400/10 border border-blue-400/30 p-2">
                                    <x-feathericon-shield class="h-5 w-5 text-blue-300" aria-hidden="true"/>
                                </div>
                                <div>
                                    <p class="text-sm font-medium text-white">Coinbase Commerce</p>
                                    <p class="text-xs text-white/50">Crypto payments</p>
                                </div>
                            </div>
                            <div class="space-y-2 text-xs text-white/60">
                                <div class="flex items-center gap-2">
                                    <x-feathericon-check class="h-3 w-3 text-emerald-400" aria-hidden="true"/>
                                    <span>Bitcoin (BTC)</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <x-feathericon-check class="h-3 w-3 text-emerald-400" aria-hidden="true"/>
                                    <span>Ethereum (ETH)</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <x-feathericon-check class="h-3 w-3 text-emerald-400" aria-hidden="true"/>
                                    <span>USDC & USDT</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Action Buttons --}}
                    <div class="mt-6 flex flex-wrap gap-3">
                        <button
                            type="button"
                            class="inline-flex items-center gap-2 rounded-lg bg-red-400/10 border border-red-400/30 px-4 py-2 text-sm font-medium text-red-300 hover:bg-red-400/20 transition-colors cursor-pointer"
                        >
                            <x-feathericon-credit-card class="h-4 w-4" aria-hidden="true"/>
                            Update Payment Method
                        </button>
                        <button
                            type="button"
                            class="inline-flex items-center gap-2 rounded-lg border border-white/10 bg-white/5 px-4 py-2 text-sm font-medium text-white/80 hover:bg-white/10 transition-colors cursor-pointer"
                        >
                            <x-feathericon-edit class="h-4 w-4" aria-hidden="true"/>
                            Change Plan
                        </button>
                        <a
                            href="#"
                            class="inline-flex items-center gap-2 text-sm text-white/60 hover:text-red-400 transition-colors px-2"
                        >
                            <x-feathericon-x-circle class="h-4 w-4" aria-hidden="true"/>
                            Cancel Subscription
                        </a>
                    </div>
                </div>

                {{-- Billing History --}}
                <div class="rounded-2xl border border-white/10 bg-gradient-to-br from-white/[0.07] to-white/[0.02] overflow-hidden">
                    <div class="px-6 md:px-8 py-6 md:py-8 border-b border-white/10">
                        <h2 class="text-lg font-semibold text-white mb-1">Billing History</h2>
                        <p class="text-sm text-white/60">Your recent payments and invoices</p>
                    </div>

                    <div class="px-6 md:px-8 py-6 md:py-8">
                        <div class="overflow-x-auto">
                            <table class="w-full text-xs sm:text-sm min-w-[700px]">
                                <thead>
                                    <tr class="border-b border-white/10">
                                        <th class="text-left py-2 sm:py-3 px-2 sm:px-4 text-white/60 font-medium">Date</th>
                                        <th class="text-left py-2 sm:py-3 px-2 sm:px-4 text-white/60 font-medium">Description</th>
                                        <th class="text-right py-2 sm:py-3 px-2 sm:px-4 text-white/60 font-medium">Amount</th>
                                        <th class="text-center py-2 sm:py-3 px-2 sm:px-4 text-white/60 font-medium">Status</th>
                                        <th class="text-center py-2 sm:py-3 px-2 sm:px-4 text-white/60 font-medium">Invoice</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @php
                                        $payments = [
                                            ['date' => 'Nov 7, 2024', 'description' => 'Pro Plan — Monthly', 'amount' => '$49.99', 'crypto' => '0.0012 BTC', 'status' => 'paid'],
                                            ['date' => 'Oct 7, 2024', 'description' => 'Pro Plan — Monthly', 'amount' => '$49.99', 'crypto' => '0.0013 BTC', 'status' => 'paid'],
                                            ['date' => 'Sep 7, 2024', 'description' => 'Pro Plan — Monthly', 'amount' => '$49.99', 'crypto' => '0.0011 BTC', 'status' => 'paid'],
                                            ['date' => 'Aug 7, 2024', 'description' => 'Pro Plan — Monthly', 'amount' => '$49.99', 'crypto' => '0.0010 BTC', 'status' => 'paid'],
                                            ['date' => 'Jul 7, 2024', 'description' => 'Pro Plan — Setup', 'amount' => '$0.00', 'crypto' => '—', 'status' => 'free'],
                                        ];
                                    @endphp

                                    @foreach($payments as $payment)
                                        <tr class="border-b border-white/5 hover:bg-white/[0.02] transition-colors">
                                            <td class="py-2 sm:py-3 px-2 sm:px-4 text-white">{{ $payment['date'] }}</td>
                                            <td class="py-2 sm:py-3 px-2 sm:px-4 text-white/70">{{ $payment['description'] }}</td>
                                            <td class="py-2 sm:py-3 px-2 sm:px-4 text-right">
                                                <div class="text-white font-medium">{{ $payment['amount'] }}</div>
                                                <div class="text-white/40 text-xs">{{ $payment['crypto'] }}</div>
                                            </td>
                                            <td class="py-2 sm:py-3 px-2 sm:px-4 text-center">
                                                @if($payment['status'] === 'paid')
                                                    <span class="inline-flex items-center gap-1 rounded-full bg-emerald-400/10 border border-emerald-400/30 px-2 py-0.5 text-xs font-medium text-emerald-300">
                                                        <x-feathericon-check class="h-3 w-3" aria-hidden="true"/>
                                                        Paid
                                                    </span>
                                                @elseif($payment['status'] === 'pending')
                                                    <span class="inline-flex items-center gap-1 rounded-full bg-yellow-400/10 border border-yellow-400/30 px-2 py-0.5 text-xs font-medium text-yellow-300">
                                                        <x-feathericon-clock class="h-3 w-3" aria-hidden="true"/>
                                                        Pending
                                                    </span>
                                                @elseif($payment['status'] === 'failed')
                                                    <span class="inline-flex items-center gap-1 rounded-full bg-red-400/10 border border-red-400/30 px-2 py-0.5 text-xs font-medium text-red-300">
                                                        <x-feathericon-x class="h-3 w-3" aria-hidden="true"/>
                                                        Failed
                                                    </span>
                                                @else
                                                    <span class="inline-flex items-center gap-1 rounded-full bg-blue-400/10 border border-blue-400/30 px-2 py-0.5 text-xs font-medium text-blue-300">
                                                        <x-feathericon-gift class="h-3 w-3" aria-hidden="true"/>
                                                        Free
                                                    </span>
                                                @endif
                                            </td>
                                            <td class="py-2 sm:py-3 px-2 sm:px-4 text-center">
                                                @if($payment['status'] === 'paid')
                                                    <a
                                                        href="#"
                                                        class="inline-flex items-center gap-1 text-white/60 hover:text-red-400 transition-colors"
                                                    >
                                                        <x-feathericon-download class="h-3.5 w-3.5" aria-hidden="true"/>
                                                        <span class="hidden sm:inline">Download</span>
                                                    </a>
                                                @else
                                                    <span class="text-white/30">—</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                {{-- Help Section --}}
                <div class="rounded-xl border border-white/10 bg-white/[0.03] p-4 md:p-6">
                    <div class="flex items-start gap-3">
                        <div class="rounded-full bg-blue-400/10 border border-blue-400/30 p-2 shrink-0">
                            <x-feathericon-help-circle class="h-4 w-4 text-blue-300" aria-hidden="true"/>
                        </div>
                        <div>
                            <h3 class="text-sm font-medium text-white mb-1">Need help with billing?</h3>
                            <p class="text-xs text-white/60 mb-3">Contact our support team for assistance with payments, invoices, or subscription changes.</p>
                            <a
                                href="#"
                                class="inline-flex items-center gap-1.5 text-xs text-red-400 hover:text-red-300 transition-colors"
                            >
                                <x-feathericon-mail class="h-3.5 w-3.5" aria-hidden="true"/>
                                Contact Support
                            </a>
                        </div>
                    </div>
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
            });
        </script>
    </x-slot:scripts>
</x-layouts.app>
