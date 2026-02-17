<x-layouts.app
    :title="'Dashboard — ' . config('app.name')"
    meta-description="Dashboard for {{ config('app.name') }}."
>
    {{-- HEAD: page-only assets --}}
    <x-slot:head>
        {{-- Using Blade Feather Icons package - no CDN needed --}}
    </x-slot:head>

    {{-- BODY TOP: dotted background --}}
    <x-slot:bodyTop>
        <div aria-hidden="true"
             class="fixed inset-0 -z-10 bg-[radial-gradient(circle_at_1px_1px,rgba(139,30,30,0.18)_1px,transparent_0)] [background-size:24px_24px]">
        </div>
    </x-slot:bodyTop>

    {{-- Navbar with Logout --}}
    <x-slot:navbar>
        <x-landing.layout.navbar
            :show-login="false"
            :show-subscribe="false"
            :show-logout="true"
        />
    </x-slot:navbar>

    <section class="px-4">
        <div class="mx-auto max-w-7xl py-8">
            {{-- Page Header --}}
            <div class="mb-6">
                <h1 class="text-2xl md:text-3xl font-bold text-white">Dashboard</h1>
                <p class="text-sm text-white/60 mt-1">Welcome back to {{ config('app.name') }}!</p>
            </div>

            {{-- Main Dashboard Container --}}
            <div class="rounded-2xl border border-white/20 bg-[#0c0a0b] overflow-hidden">
                {{-- Tabs Navigation --}}
                <div class="border-b border-white/20 bg-white/[0.02] relative">
                    <div id="scroll-container" class="overflow-x-auto scroll-smooth" style="scrollbar-width: none; -ms-overflow-style: none; -webkit-overflow-scrolling: touch;">
                        <nav id="tabs-nav" class="flex gap-4 px-2 py-2 w-max" role="tablist">
                            <button
                                type="button"
                                role="tab"
                                aria-selected="true"
                                aria-controls="tab-dashboard"
                                data-tab="dashboard"
                                class="tab-button active flex items-center justify-center gap-2 w-40 py-2.5 rounded-lg text-sm font-medium transition-all whitespace-nowrap flex-shrink-0"
                            >
                                <x-feathericon-activity class="h-4 w-4 shrink-0" aria-hidden="true"/>
                                <span>Dashboard</span>
                            </button>
                            <button
                                type="button"
                                role="tab"
                                aria-selected="false"
                                aria-controls="tab-accounts"
                                data-tab="accounts"
                                class="tab-button flex items-center justify-center gap-2 w-40 py-2.5 rounded-lg text-sm font-medium transition-all whitespace-nowrap flex-shrink-0"
                            >
                                <x-feathericon-briefcase class="h-4 w-4 shrink-0" aria-hidden="true"/>
                                <span>Accounts</span>
                            </button>
                            <button
                                type="button"
                                role="tab"
                                aria-selected="false"
                                aria-controls="tab-billing"
                                data-tab="billing"
                                class="tab-button flex items-center justify-center gap-2 w-40 py-2.5 rounded-lg text-sm font-medium transition-all whitespace-nowrap flex-shrink-0"
                            >
                                <x-feathericon-credit-card class="h-4 w-4 shrink-0" aria-hidden="true"/>
                                <span>Billing</span>
                            </button>
                            <button
                                type="button"
                                role="tab"
                                aria-selected="false"
                                aria-controls="tab-profile"
                                data-tab="profile"
                                class="tab-button flex items-center justify-center gap-2 w-40 py-2.5 rounded-lg text-sm font-medium transition-all whitespace-nowrap flex-shrink-0"
                            >
                                <x-feathericon-user class="h-4 w-4 shrink-0" aria-hidden="true"/>
                                <span>My Profile</span>
                            </button>
                        </nav>
                    </div>
                </div>

                {{-- Tab Panels --}}
                <div class="p-4 sm:p-6 md:p-8">
                    {{-- Dashboard Tab --}}
                    <div id="tab-dashboard" role="tabpanel" class="tab-panel">
                        <div class="flex flex-col sm:flex-row items-start sm:items-center gap-3 mb-6">
                            <span class="inline-grid h-9 w-9 sm:h-10 sm:w-10 place-items-center rounded-full bg-emerald-500/10 border border-emerald-400/30 shrink-0">
                                <x-feathericon-activity class="h-4 w-4 sm:h-5 sm:w-5 text-emerald-300" aria-hidden="true"/>
                            </span>
                            <div>
                                <h2 class="text-base sm:text-lg font-semibold text-white">Dashboard Overview</h2>
                                <p class="text-xs sm:text-sm text-white/60">Monitor your trading bot performance</p>
                            </div>
                        </div>
                        <div class="text-white/80 text-sm sm:text-base">
                            <p>Dashboard content coming soon...</p>
                        </div>
                    </div>

                    {{-- Accounts Tab --}}
                    <div id="tab-accounts" role="tabpanel" class="tab-panel hidden">
                        <div class="flex flex-col sm:flex-row items-start sm:items-center gap-3 mb-6">
                            <span class="inline-grid h-9 w-9 sm:h-10 sm:w-10 place-items-center rounded-full bg-blue-500/10 border border-blue-400/30 shrink-0">
                                <x-feathericon-briefcase class="h-4 w-4 sm:h-5 sm:w-5 text-blue-300" aria-hidden="true"/>
                            </span>
                            <div>
                                <h2 class="text-base sm:text-lg font-semibold text-white">Trading Accounts</h2>
                                <p class="text-xs sm:text-sm text-white/60">Manage your connected exchange accounts</p>
                            </div>
                        </div>

                        @if($accounts->isEmpty())
                            <div class="text-center py-12">
                                <div class="inline-grid h-16 w-16 place-items-center rounded-full bg-white/5 border border-white/10 mb-4">
                                    <x-feathericon-briefcase class="h-8 w-8 text-white/40" aria-hidden="true"/>
                                </div>
                                <h3 class="text-lg font-semibold text-white mb-2">No Trading Accounts</h3>
                                <p class="text-sm text-white/60 mb-6">Connect your first exchange account to start trading</p>
                                <x-ui.button icon="plus" status="active">
                                    Add Account
                                </x-ui.button>
                            </div>
                        @else
                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                                @foreach($accounts as $account)
                                    <div class="group relative rounded-2xl border border-white/10 bg-gradient-to-br from-white/[0.07] to-white/[0.02] p-6 hover:border-white/20 hover:from-white/[0.09] hover:to-white/[0.04] transition-all duration-300 hover:shadow-lg hover:shadow-red-500/5">
                                        {{-- Status Badge & Disable Link Container --}}
                                        <div class="absolute top-6 right-6 flex flex-col items-end gap-2">
                                            @if($account->is_active)
                                                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-emerald-500/10 border border-emerald-400/30 text-emerald-300 text-xs font-medium backdrop-blur-sm">
                                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                                                    Active
                                                </span>
                                            @else
                                                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-white/5 border border-white/20 text-white/60 text-xs font-medium backdrop-blur-sm">
                                                    <span class="w-1.5 h-1.5 rounded-full bg-white/60"></span>
                                                    Inactive
                                                </span>
                                            @endif

                                            <a
                                                href="#"
                                                onclick="event.preventDefault(); window.showModal('disable-account-{{ $account->id }}')"
                                                class="inline-flex items-center gap-1.5 text-xs text-white/40 hover:text-red-400 transition-colors"
                                            >
                                                <x-feathericon-power class="h-3 w-3" aria-hidden="true"/>
                                                Disable Account
                                            </a>
                                        </div>

                                        {{-- Disable Account Confirmation Modal --}}
                                        <x-ui.modal
                                            id="disable-account-{{ $account->id }}"
                                            title="Disable Trading Account"
                                            confirm-text="Disable Account"
                                            confirm-icon="power"
                                            danger="true"
                                        >
                                            <p class="mb-3">Are you sure you want to disable <strong class="text-white font-semibold">{{ $account->name }}</strong>?</p>
                                            <p class="text-white/60 text-xs">This will stop all trading activity on this account. You can re-enable it later.</p>
                                        </x-ui.modal>

                                        {{-- Account Header --}}
                                        <div class="flex items-start gap-4 mb-6 pr-24">
                                            @if($account->apiSystem?->logo_url)
                                                <div class="inline-flex h-12 w-12 items-center justify-center rounded-xl bg-white/5 border border-white/10 p-2.5 backdrop-blur-sm flex-shrink-0">
                                                    <img src="{{ $account->apiSystem->logo_url }}" alt="{{ $account->apiSystem->name }}" class="h-full w-full object-contain">
                                                </div>
                                            @else
                                                <div class="inline-grid h-12 w-12 place-items-center rounded-xl bg-red-500/10 border border-red-400/30 backdrop-blur-sm flex-shrink-0">
                                                    <x-feathericon-briefcase class="h-6 w-6 text-red-300" aria-hidden="true"/>
                                                </div>
                                            @endif
                                            <div class="min-w-0 flex-1">
                                                <h3 class="text-lg font-semibold text-white truncate mb-1">{{ $account->name }}</h3>
                                                <p class="text-xs text-white/50 flex items-center gap-1.5">
                                                    <x-feathericon-clock class="h-3 w-3" aria-hidden="true"/>
                                                    <span>{{ $account->created_at->diffForHumans() }}</span>
                                                </p>
                                            </div>
                                        </div>

                                        {{-- Analytics --}}
                                        <div class="mb-6 py-4 px-4 rounded-lg bg-white/[0.02]">
                                            <div class="grid grid-cols-2 gap-x-4 gap-y-4 sm:gap-x-6">
                                                {{-- Total Profit --}}
                                                <div>
                                                    <p class="text-xs text-white/50 mb-1 flex items-center gap-1.5">
                                                        <x-feathericon-trending-up class="h-3 w-3" aria-hidden="true"/>
                                                        Total Profit
                                                    </p>
                                                    <p class="text-xl font-semibold text-emerald-300">{{ number_format($account->profit_percentage, 2) }}%</p>
                                                </div>

                                                {{-- Daily Profit --}}
                                                <div>
                                                    <p class="text-xs text-white/50 mb-1 flex items-center gap-1.5">
                                                        <x-feathericon-calendar class="h-3 w-3" aria-hidden="true"/>
                                                        Daily Profit
                                                    </p>
                                                    <p class="text-xl font-semibold text-emerald-300">+0.12%</p>
                                                </div>

                                                {{-- Current PnL --}}
                                                <div>
                                                    <p class="text-xs text-white/50 mb-1 flex items-center gap-1.5">
                                                        <x-feathericon-dollar-sign class="h-3 w-3" aria-hidden="true"/>
                                                        Current PnL
                                                    </p>
                                                    <p class="text-xl font-semibold text-white">$245.80</p>
                                                </div>

                                                {{-- Win Rate --}}
                                                <div>
                                                    <p class="text-xs text-white/50 mb-1 flex items-center gap-1.5">
                                                        <x-feathericon-target class="h-3 w-3" aria-hidden="true"/>
                                                        Win Rate
                                                    </p>
                                                    <p class="text-xl font-semibold text-white">68.5%</p>
                                                </div>
                                            </div>
                                        </div>

                                        {{-- Quick Info --}}
                                        <div class="grid grid-cols-2 gap-3 mb-6">
                                            <div class="p-3 rounded-lg bg-white/[0.03] border border-white/5">
                                                <p class="text-xs text-white/50 mb-1.5 flex items-center gap-1.5">
                                                    <x-feathericon-activity class="h-3 w-3" aria-hidden="true"/>
                                                    Trading Status
                                                </p>
                                                @if($account->can_trade)
                                                    <p class="text-sm font-semibold text-emerald-300 flex items-center gap-1.5">
                                                        <x-feathericon-check-circle class="h-4 w-4" aria-hidden="true"/>
                                                        Enabled
                                                    </p>
                                                @else
                                                    <p class="text-sm font-semibold text-white/60 flex items-center gap-1.5">
                                                        <x-feathericon-x-circle class="h-4 w-4" aria-hidden="true"/>
                                                        Disabled
                                                    </p>
                                                @endif
                                            </div>
                                            <div class="p-3 rounded-lg bg-white/[0.03] border border-white/5">
                                                <p class="text-xs text-white/50 mb-1.5 flex items-center gap-1.5">
                                                    <x-feathericon-zap class="h-3 w-3" aria-hidden="true"/>
                                                    Exchange
                                                </p>
                                                <div class="flex items-center gap-2">
                                                    @if($account->apiSystem?->logo_url)
                                                        <img src="{{ $account->apiSystem->logo_url }}" alt="{{ $account->apiSystem->name }}" class="h-4 w-4 object-contain">
                                                    @endif
                                                    <p class="text-sm font-semibold text-white truncate">{{ $account->apiSystem?->name ?? 'N/A' }}</p>
                                                </div>
                                            </div>
                                        </div>

                                        {{-- Account Actions --}}
                                        <div class="grid grid-cols-2 gap-2">
                                            <x-ui.button icon="settings" status="active" :full="true">
                                                Configure
                                            </x-ui.button>
                                            <x-ui.button
                                                href="{{ route('account.analytics', ['id' => $account->id]) }}"
                                                icon="bar-chart-2"
                                                status="active"
                                                :full="true"
                                            >
                                                Analytics
                                            </x-ui.button>
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                            {{-- Add Account Button --}}
                            <div class="mt-6">
                                <x-ui.button icon="plus" status="active">
                                    Add New Account
                                </x-ui.button>
                            </div>
                        @endif
                    </div>

                    {{-- Billing Tab --}}
                    <div id="tab-billing" role="tabpanel" class="tab-panel hidden">
                        <div class="flex flex-col sm:flex-row items-start sm:items-center gap-3 mb-6">
                            <span class="inline-grid h-9 w-9 sm:h-10 sm:w-10 place-items-center rounded-full bg-blue-500/10 border border-blue-400/30 shrink-0">
                                <x-feathericon-credit-card class="h-4 w-4 sm:h-5 sm:w-5 text-blue-300" aria-hidden="true"/>
                            </span>
                            <div>
                                <h2 class="text-base sm:text-lg font-semibold text-white">Billing</h2>
                                <p class="text-xs sm:text-sm text-white/60">Manage your subscription and payment methods</p>
                            </div>
                        </div>
                        {{-- Current Subscription Summary --}}
                        <div class="max-w-3xl">
                            <div class="rounded-2xl border border-white/10 bg-gradient-to-br from-white/[0.07] to-white/[0.02] overflow-hidden">
                                {{-- Header --}}
                                <div class="flex items-start justify-between px-6 py-5 border-b border-white/10">
                                    <div>
                                        <h3 class="text-base font-semibold text-white mb-1">Current Subscription</h3>
                                        <p class="text-sm text-white/60">Your active plan and billing status</p>
                                    </div>
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-400/10 border border-emerald-400/30 px-3 py-1 text-xs font-medium text-emerald-300">
                                        <x-feathericon-check-circle class="h-3 w-3" aria-hidden="true"/>
                                        Active
                                    </span>
                                </div>

                                {{-- Content --}}
                                <div class="px-6 py-5">
                                    <div class="grid sm:grid-cols-2 gap-6 mb-6">
                                        {{-- Plan --}}
                                        <div>
                                            <p class="text-xs text-white/50 mb-1">Plan</p>
                                            <p class="text-lg font-bold text-white">Pro Plan</p>
                                            <p class="text-sm text-white/60 mt-0.5">$49.99 / month</p>
                                        </div>

                                        {{-- Next Billing --}}
                                        <div>
                                            <p class="text-xs text-white/50 mb-1">Next Billing Date</p>
                                            <p class="text-lg font-bold text-white">Dec 7, 2024</p>
                                            <p class="text-sm text-white/60 mt-0.5">via Coinbase Commerce</p>
                                        </div>
                                    </div>

                                    {{-- Actions --}}
                                    <div class="flex flex-wrap gap-3">
                                        <a
                                            href="{{ route('billing') }}"
                                            class="inline-flex items-center gap-2 rounded-lg bg-red-400/10 border border-red-400/30 px-4 py-2 text-sm font-medium text-red-300 hover:bg-red-400/20 transition-colors"
                                        >
                                            <x-feathericon-credit-card class="h-4 w-4" aria-hidden="true"/>
                                            Manage Billing
                                        </a>
                                        <a
                                            href="{{ route('billing') }}"
                                            class="inline-flex items-center gap-2 text-sm text-white/60 hover:text-red-400 transition-colors px-2"
                                        >
                                            <x-feathericon-file-text class="h-4 w-4" aria-hidden="true"/>
                                            View Invoices
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- My Profile Tab --}}
                    <div id="tab-profile" role="tabpanel" class="tab-panel hidden">
                        <div class="flex flex-col sm:flex-row items-start sm:items-center gap-3 mb-6">
                            <span class="inline-grid h-9 w-9 sm:h-10 sm:w-10 place-items-center rounded-full bg-red-500/10 border border-red-400/30 shrink-0">
                                <x-feathericon-user class="h-4 w-4 sm:h-5 sm:w-5 text-red-300" aria-hidden="true"/>
                            </span>
                            <div>
                                <h2 class="text-base sm:text-lg font-semibold text-white">My Profile</h2>
                                <p class="text-xs sm:text-sm text-white/60">Update your personal information and password</p>
                            </div>
                        </div>

                        @php
                            $rawChannels = collect(auth()->user()->notification_channels)->map(function($channel) {
                                return str_contains($channel, 'Pushover') ? 'pushover' : $channel;
                            })->toArray();

                            // If there's a validation error for notification_channels, the form was submitted
                            // Use old() value (or empty array if none checked), otherwise use DB values
                            if ($errors->has('notification_channels')) {
                                $oldChannels = old('notification_channels', []);
                            } else {
                                $oldChannels = $rawChannels;
                            }

                            $initialPushoverChecked = in_array('pushover', $oldChannels);
                            $initialPushoverKey = old('pushover_key', auth()->user()->pushover_key);
                        @endphp

                        <form
                            method="POST"
                            action="{{ route('profile.update') }}"
                            class="max-w-2xl"
                            novalidate
                            x-data="profileForm"
                            x-effect="if (!pushoverChecked) pushoverKey = ''"
                        >
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="_tab" value="profile">

                            {{-- Personal Information Section --}}
                            <div class="mb-6">
                                <h3 class="text-base font-semibold text-white mb-2">Personal Information</h3>
                                <p class="text-sm text-white/60 mb-6">Update your account details</p>
                            </div>

                            {{-- Name Field --}}
                            <div class="mb-6">
                                <label for="name" class="block text-sm font-medium text-white/80 mb-2">
                                    Name <span class="text-red-400" aria-hidden="true">*</span>
                                </label>
                                <x-ui.input
                                    id="name"
                                    name="name"
                                    type="text"
                                    :value="old('name', auth()->user()->name)"
                                    leading-icon="user"
                                />
                            </div>

                            {{-- Email Field --}}
                            <div class="mb-6">
                                <label for="email" class="block text-sm font-medium text-white/80 mb-2">
                                    Email <span class="text-red-400" aria-hidden="true">*</span>
                                </label>
                                <x-ui.input
                                    id="email"
                                    name="email"
                                    type="email"
                                    :value="old('email', auth()->user()->email)"
                                    leading-icon="mail"
                                    notice="Your email is also where we send you important notifications when needed"
                                />
                            </div>

                            {{-- Last Login --}}
                            @if(auth()->user()->previous_logged_in_at)
                                <div class="mb-6">
                                    <label class="block text-sm font-medium text-white/80 mb-2">
                                        Last Login
                                    </label>
                                    <div class="flex items-center gap-2 text-sm text-white/60">
                                        <x-feathericon-clock class="h-4 w-4"/>
                                        <span>
                                            {{ auth()->user()->previous_logged_in_at->diffForHumans() }}
                                            <span class="text-white/40">
                                                ({{ auth()->user()->previous_logged_in_at->format('M d, Y \a\t g:i A') }})
                                            </span>
                                        </span>
                                    </div>
                                </div>
                            @endif

                            {{-- Notification Channels --}}
                            <div class="mt-8 pt-6 border-t border-white/20">
                                <h3 class="text-base font-semibold text-white mb-2">Notifications</h3>
                                <p class="text-sm text-white/60 mb-6">Select at least one notification method</p>

                                <div class="space-y-3">
                                    {{-- Email Notifications --}}
                                    <x-ui.checkbox
                                        id="notification_email"
                                        name="notification_channels[]"
                                        value="mail"
                                        :checked="in_array('mail', $oldChannels)"
                                        icon="mail"
                                        title="Email"
                                        description="Receive notifications via email"
                                        suppress-error="true"
                                    />

                                    {{-- Pushover Notifications --}}
                                    <x-ui.checkbox
                                        id="notification_pushover"
                                        name="notification_channels[]"
                                        value="pushover"
                                        :checked="in_array('pushover', $oldChannels)"
                                        icon="smartphone"
                                        title="Pushover"
                                        description="Receive push notifications on your devices"
                                        suppress-error="true"
                                        @change="pushoverChecked = $event.target.checked"
                                    />
                                </div>

                                @error('notification_channels')
                                    <p class="mt-3 text-xs text-red-400">{{ $message }}</p>
                                @enderror

                                {{-- Pushover Key Field --}}
                                <div class="mt-6">
                                    <label for="pushover_key" class="block text-sm font-medium text-white/80 mb-2">
                                        Pushover API Key
                                    </label>
                                    <div class="space-y-2 [&_svg]:stroke-current [&_svg]:text-current">
                                        <div class="relative">
                                            <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-white/60 [&_svg]:h-4 [&_svg]:w-4">
                                                <x-feathericon-key class="h-4 w-4"/>
                                            </span>
                                            <input
                                                id="pushover_key"
                                                name="pushover_key"
                                                type="text"
                                                placeholder="Enter your Pushover user/group key"
                                                x-model="pushoverKey"
                                                :disabled="pushoverFieldDisabled"
                                                :class="pushoverFieldDisabled ? 'opacity-60 cursor-not-allowed' : ''"
                                                class="w-full rounded-lg bg-white/5 text-white placeholder-white/40 outline-none transition border py-0 h-12 text-base pl-10 pr-10 border-white/10 focus:ring-2 focus:ring-white/10 focus:border-white/20"
                                            />
                                        </div>
                                        <p class="text-xs text-white/40">
                                            Required if you want to receive Pushover notifications
                                        </p>
                                    </div>
                                    <div class="mt-4 pt-2">
                                        <button
                                            type="button"
                                            @click="testPushover"
                                            :disabled="testButtonDisabled"
                                            :class="testButtonDisabled ? 'opacity-60 cursor-not-allowed' : ''"
                                            class="inline-flex items-center gap-2 rounded-lg bg-red-400/10 border border-red-400/30 px-4 py-2 text-sm font-medium text-red-300 hover:bg-red-400/20 transition-colors disabled:hover:bg-red-400/10"
                                        >
                                            <x-feathericon-arrow-right class="h-4 w-4" x-show="!testingPushover"/>
                                            <x-feathericon-loader class="h-4 w-4 animate-spin" x-show="testingPushover" style="display: none;"/>
                                            <span x-text="testingPushover ? 'Sending...' : 'Test Pushover'"></span>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            {{-- Password Section --}}
                            <div class="mt-8 pt-6 border-t border-white/20">
                                <h3 class="text-base font-semibold text-white mb-2">Change Password</h3>
                                <p class="text-sm text-white/60 mb-2">Leave blank to keep your current password</p>
                                <p class="text-xs text-yellow-400/80 mb-6">
                                    <x-feathericon-alert-circle class="inline h-3 w-3 mr-1"/>
                                    If you change your password you will need to login again
                                </p>

                                {{-- New Password --}}
                                <div class="mb-6">
                                    <label for="password" class="block text-sm font-medium text-white/80 mb-2">
                                        New Password
                                    </label>
                                    <x-ui.input
                                        id="password"
                                        name="password"
                                        type="password"
                                        leading-icon="lock"
                                        autocomplete="new-password"
                                    />
                                </div>

                                {{-- Confirm New Password --}}
                                <div class="mb-6">
                                    <label for="password_confirmation" class="block text-sm font-medium text-white/80 mb-2">
                                        Confirm New Password
                                    </label>
                                    <x-ui.input
                                        id="password_confirmation"
                                        name="password_confirmation"
                                        type="password"
                                        leading-icon="lock"
                                        autocomplete="new-password"
                                    />
                                </div>
                            </div>

                            {{-- Submit Button --}}
                            <div class="mt-8">
                                <x-ui.button
                                    type="submit"
                                    status="active"
                                    icon="save"
                                    class="h-12 px-6"
                                >
                                    Update Profile
                                </x-ui.button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <x-slot:footer>
        <x-landing.layout.footer />
    </x-slot:footer>

    {{-- SCRIPTS: Tab functionality --}}
    <x-slot:scripts>
        <script>
            // Alpine.js component for profile form
            document.addEventListener('alpine:init', () => {
                Alpine.data('profileForm', () => ({
                    pushoverChecked: {{ $initialPushoverChecked ? 'true' : 'false' }},
                    pushoverKey: '{{ $initialPushoverKey }}',
                    testingPushover: false,

                    get pushoverFieldDisabled() {
                        return !this.pushoverChecked;
                    },

                    get testButtonDisabled() {
                        return !this.pushoverChecked || this.pushoverKey.trim() === '' || this.testingPushover;
                    },

                    async testPushover() {
                        this.testingPushover = true;
                        const testUrl = '{{ route('profile.test-pushover') }}';
                        const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

                        try {
                            const response = await fetch(testUrl, {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': csrfToken
                                },
                                body: JSON.stringify({
                                    pushover_key: this.pushoverKey
                                })
                            });

                            const data = await response.json();

                            if (typeof window.showToast === 'function') {
                                window.showToast(data.message, data.success ? 'success' : 'error');
                            }
                        } catch (error) {
                            if (typeof window.showToast === 'function') {
                                window.showToast('Failed to send test notification', 'error');
                            }
                        } finally {
                            this.testingPushover = false;

                            // Re-render Feather icons after state change
                            if (typeof feather !== 'undefined') {
                                requestAnimationFrame(function() {
                                    feather.replace();
                                });
                            }
                        }
                    }
                }));
            });

            document.addEventListener('DOMContentLoaded', function() {
                // Modal show/hide functions
                window.showModal = function(modalId) {
                    const modal = document.getElementById(modalId);
                    if (modal) {
                        const content = modal.querySelector('.modal-content');

                        // Show modal
                        modal.classList.remove('hidden');
                        document.body.style.overflow = 'hidden';

                        // Trigger animation on next frame
                        requestAnimationFrame(() => {
                            modal.classList.remove('opacity-0');
                            modal.classList.add('opacity-100');

                            if (content) {
                                content.classList.remove('opacity-0', 'scale-95');
                                content.classList.add('opacity-100', 'scale-100');
                            }

                            // Re-render Feather icons
                            if (typeof feather !== 'undefined') {
                                feather.replace();
                            }
                        });
                    }
                };

                window.hideModal = function(modalId) {
                    const modal = document.getElementById(modalId);
                    if (modal) {
                        const content = modal.querySelector('.modal-content');

                        // Start hide animation
                        modal.classList.remove('opacity-100');
                        modal.classList.add('opacity-0');

                        if (content) {
                            content.classList.remove('opacity-100', 'scale-100');
                            content.classList.add('opacity-0', 'scale-95');
                        }

                        // Wait for animation to complete before hiding
                        setTimeout(() => {
                            modal.classList.add('hidden');
                            document.body.style.overflow = '';
                        }, 500);
                    }
                };

                // Close modal on ESC key
                document.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape') {
                        const openModal = document.querySelector('.modal-overlay:not(.hidden)');
                        if (openModal) {
                            window.hideModal(openModal.id);
                        }
                    }
                });

                // Initialize Feather icons
                if (typeof feather !== 'undefined') {
                    feather.replace();
                }

                // Show toast notification if session message exists
                // Wait for app.js to initialize toast system
                setTimeout(function() {
                    @if (session('status'))
                        if (typeof window.showToast === 'function') {
                            window.showToast('{{ session('status') }}', 'success');
                        }
                    @endif

                    @if (session('profile_updated'))
                        if (typeof window.showToast === 'function') {
                            window.showToast('{{ session('profile_updated') }}', 'success');
                        }
                    @endif

                    @if ($errors->any())
                        if (typeof window.showToast === 'function') {
                            window.showToast('There were validation errors, please check the form fields below', 'error');
                        }
                    @endif
                }, 100);

                // Tab switching functionality
                const tabButtons = document.querySelectorAll('.tab-button');
                const tabPanels = document.querySelectorAll('.tab-panel');

                // Function to switch to a specific tab
                function switchToTab(targetTab) {
                    // Update button states
                    tabButtons.forEach(function(btn) {
                        btn.classList.remove('active');
                        btn.setAttribute('aria-selected', 'false');
                    });

                    const targetButton = document.querySelector('[data-tab="' + targetTab + '"]');
                    if (targetButton) {
                        targetButton.classList.add('active');
                        targetButton.setAttribute('aria-selected', 'true');

                        // Scroll the tab into view
                        targetButton.scrollIntoView({
                            behavior: 'smooth',
                            block: 'nearest',
                            inline: 'center'
                        });
                    }

                    // Update panel visibility
                    tabPanels.forEach(function(panel) {
                        panel.classList.add('hidden');
                    });

                    const targetPanel = document.getElementById('tab-' + targetTab);
                    if (targetPanel) {
                        targetPanel.classList.remove('hidden');
                    }

                    // Update URL hash (no page reload, no security warnings)
                    window.location.hash = targetTab;

                    // Re-render Feather icons after tab switch (wait for DOM update)
                    if (typeof feather !== 'undefined') {
                        // Use requestAnimationFrame to ensure DOM is updated
                        requestAnimationFrame(function() {
                            feather.replace();
                        });
                    }
                }

                // Restore active tab from session or URL hash on page load
                const sessionTab = @json(session('active_tab'));
                const hash = window.location.hash.substring(1);

                // Priority: session tab > URL hash
                const targetTab = sessionTab || hash;
                if (targetTab) {
                    switchToTab(targetTab);
                } else {
                    // Even if no tab switch, ensure icons are rendered on first load
                    if (typeof feather !== 'undefined') {
                        feather.replace();
                    }
                }

                // Add click listeners to tab buttons
                tabButtons.forEach(function(button) {
                    button.addEventListener('click', function(e) {
                        e.preventDefault();
                        const targetTab = this.getAttribute('data-tab');
                        switchToTab(targetTab);
                    });
                });

                // Drag-to-scroll functionality
                const scrollContainer = document.getElementById('scroll-container');

                if (scrollContainer) {
                    let isDown = false;
                    let startX;
                    let scrollLeft;
                    let hasMoved = false;

                    // Check if tabs are scrollable and update cursor
                    function updateCursor() {
                        const isScrollable = scrollContainer.scrollWidth > scrollContainer.clientWidth;
                        if (isScrollable) {
                            scrollContainer.classList.add('cursor-grab');
                        } else {
                            scrollContainer.classList.remove('cursor-grab', 'active');
                        }
                    }

                    // Initial check and on resize
                    updateCursor();
                    window.addEventListener('resize', updateCursor);

                    scrollContainer.addEventListener('mousedown', function(e) {
                        const isScrollable = scrollContainer.scrollWidth > scrollContainer.clientWidth;
                        if (!isScrollable) return;

                        isDown = true;
                        hasMoved = false;
                        scrollContainer.classList.add('cursor-grabbing');
                        scrollContainer.classList.remove('cursor-grab');
                        startX = e.pageX - scrollContainer.offsetLeft;
                        scrollLeft = scrollContainer.scrollLeft;
                    });

                    scrollContainer.addEventListener('mouseleave', function() {
                        isDown = false;
                        scrollContainer.classList.remove('cursor-grabbing');
                        if (scrollContainer.scrollWidth > scrollContainer.clientWidth) {
                            scrollContainer.classList.add('cursor-grab');
                        }
                    });

                    scrollContainer.addEventListener('mouseup', function() {
                        isDown = false;
                        scrollContainer.classList.remove('cursor-grabbing');
                        if (scrollContainer.scrollWidth > scrollContainer.clientWidth) {
                            scrollContainer.classList.add('cursor-grab');
                        }
                    });

                    scrollContainer.addEventListener('mousemove', function(e) {
                        if (!isDown) return;
                        e.preventDefault();
                        hasMoved = true;
                        const x = e.pageX - scrollContainer.offsetLeft;
                        const walk = (x - startX) * 2; // Scroll speed multiplier
                        scrollContainer.scrollLeft = scrollLeft - walk;
                    });

                    // Prevent tab clicks if dragging
                    scrollContainer.addEventListener('click', function(e) {
                        if (hasMoved) {
                            e.preventDefault();
                            e.stopPropagation();
                        }
                    }, true);
                }

                // Handle Disable Account confirmation
                document.addEventListener('click', function(e) {
                    if (e.target.closest('[data-modal-confirm^="disable-account-"]')) {
                        const modalId = e.target.closest('[data-modal-confirm^="disable-account-"]').getAttribute('data-modal-confirm');
                        const accountId = modalId.replace('disable-account-', '');

                        // TODO: Add AJAX request to disable account
                        console.log('Disabling account:', accountId);

                        // Close modal
                        window.hideModal(modalId);

                        // Show success toast
                        if (typeof window.showToast === 'function') {
                            window.showToast('Account has been disabled', 'success');
                        }
                    }
                });
            });
        </script>

        <style>
            .tab-button {
                color: rgb(255, 255, 255);
                background: linear-gradient(to bottom right, rgba(239, 68, 68, 0.03), rgba(220, 38, 38, 0.015));
                border: 1px solid rgba(239, 68, 68, 0.2);
                cursor: pointer;
            }

            .tab-button:hover {
                color: rgb(255, 255, 255);
                background: linear-gradient(to bottom right, rgba(239, 68, 68, 0.05), rgba(220, 38, 38, 0.025));
                border-color: rgba(239, 68, 68, 0.3);
            }

            .tab-button.active {
                color: rgb(239, 68, 68);
                background: linear-gradient(to bottom right, rgba(239, 68, 68, 0.1), rgba(220, 38, 38, 0.05));
                border: 1px solid rgba(239, 68, 68, 0.3);
                box-shadow: 0 0 0 1px rgba(239, 68, 68, 0.2),
                            0 0 12px rgba(239, 68, 68, 0.15);
            }

            .tab-button.active:hover {
                background: linear-gradient(to bottom right, rgba(239, 68, 68, 0.15), rgba(220, 38, 38, 0.075));
                border-color: rgba(239, 68, 68, 0.4);
            }

            /* Remove content animations */
            .tab-panel.hidden {
                display: none;
            }

            /* Hide scrollbar but keep functionality */
            #scroll-container::-webkit-scrollbar {
                display: none;
            }

            /* Ensure smooth scrolling on iOS */
            #scroll-container {
                -webkit-overflow-scrolling: touch;
            }
        </style>
    </x-slot:scripts>
</x-layouts.app>
