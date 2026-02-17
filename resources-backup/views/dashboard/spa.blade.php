{{-- Single Page Dashboard (Alpine SPA) --}}
<x-layouts.dashboard title="Dashboard — {{ config('app.name') }}">
    {{-- Sidebar --}}
    <x-slot:sidebar>
        <x-ui.sidebar>
            <x-ui.sidebar.item section="dashboard" icon="activity" label="Dashboard" />
            <x-ui.sidebar.item section="accounts" icon="briefcase" label="Accounts" />
            <x-ui.sidebar.item section="analytics" icon="bar-chart-2" label="Analytics" />
            <x-ui.sidebar.item section="profile" icon="user" label="My Profile" />
        </x-ui.sidebar>
    </x-slot:sidebar>

        {{-- Main Content Sections --}}
        <div class="p-4 sm:p-6 lg:p-8" x-data="dashboardApp()" x-init="init()" @navigate-to.window="navigateTo($event.detail)">
            {{-- Dashboard Section --}}
            <div x-show="activeSection === 'dashboard'" x-transition:enter.duration.300ms>
                {{-- Header with Account Selector --}}
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
                    <x-dashboard.section-header
                        title="Dashboard"
                        subtitle="Welcome back to {{ config('app.name') }}!"
                    />

                    {{-- Account Selector with neon glow --}}
                    <div class="flex items-center gap-2 px-3 py-2 rounded-xl relative backdrop-blur-xl transition-all duration-300 hover:scale-105" style="background: linear-gradient(135deg, rgba(239,68,68,0.25), rgba(239,68,68,0.15)); border: 1px solid rgba(239,68,68,0.6); box-shadow: 0 0 30px rgba(239,68,68,0.3), inset 0 1px 0 rgba(255,255,255,0.2);">
                        {{-- Animated glow --}}
                        <div class="absolute inset-0 rounded-xl pointer-events-none" style="background: linear-gradient(135deg, rgba(255,255,255,0.15) 0%, transparent 60%); animation: pulse 3s ease-in-out infinite;"></div>

                        <div class="relative z-10 flex items-center gap-2">
                            <x-feathericon-briefcase class="h-4 w-4 text-white drop-shadow-lg" />
                            <span class="text-white text-xs font-bold uppercase tracking-wider" style="font-family: 'Space Grotesk', sans-serif;">Account:</span>
                            <x-ui.select theme="red" class="w-auto min-w-[180px] !h-7 !py-0 !text-xs font-medium">
                                <option>Main Trading Account</option>
                                <option>Secondary Account</option>
                                <option>Test Account</option>
                            </x-ui.select>
                        </div>
                    </div>
                </div>

                {{-- Loading State --}}
                <div x-show="loading" class="text-center py-12">
                    <div class="inline-flex items-center gap-3 px-6 py-3 rounded-xl bg-white/5 border border-white/10">
                        <svg class="animate-spin h-5 w-5 text-{{ theme('primary.text') }}" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span class="text-white/80 text-sm">Loading dashboard data...</span>
                    </div>
                </div>

                {{-- Dashboard Content (Hidden while loading) --}}
                <div x-show="!loading">
                    {{-- Global Stats Card --}}
                    <div x-show="dashboardData?.global_stats" class="rounded-2xl p-4 mb-6 relative overflow-hidden backdrop-blur-2xl" style="background: linear-gradient(135deg, rgba(255,255,255,0.07) 0%, rgba(255,255,255,0.04) 100%); border: 1px solid rgba(255,255,255,0.12); box-shadow: 0 8px 32px rgba(0,0,0,0.3), inset 0 1px 0 rgba(255,255,255,0.15);">
                        {{-- Glassmorphism overlay --}}
                        <div class="absolute inset-0 rounded-2xl pointer-events-none opacity-40" style="background: radial-gradient(circle at 20% 20%, rgba(255,255,255,0.06) 0%, transparent 50%);"></div>

                        <div class="relative z-10">
                            <h2 class="text-sm font-bold text-white/90 mb-3 uppercase tracking-wider" style="font-family: 'Space Grotesk', sans-serif;">Global Statistics</h2>

                            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4">
                                {{-- Gross Revenue --}}
                                <div class="flex flex-col gap-1">
                                    <div class="flex items-center gap-2 text-white/60">
                                        <x-feathericon-dollar-sign class="h-4 w-4" />
                                        <span class="text-xs uppercase tracking-wide font-medium" style="font-family: 'Space Grotesk', sans-serif;">Gross Revenue</span>
                                    </div>
                                    <p class="text-lg font-bold text-white" style="font-family: 'JetBrains Mono', monospace; font-feature-settings: 'tnum', 'lnum';" x-text="formatStatValue('gross_revenue')"></p>
                                </div>

                                {{-- Daily Gross --}}
                                <div class="flex flex-col gap-1">
                                    <div class="flex items-center gap-2 text-white/60">
                                        <x-feathericon-calendar class="h-4 w-4" />
                                        <span class="text-xs uppercase tracking-wide font-medium" style="font-family: 'Space Grotesk', sans-serif;">Daily Gross</span>
                                    </div>
                                    <p class="text-lg font-bold text-white" style="font-family: 'JetBrains Mono', monospace; font-feature-settings: 'tnum', 'lnum';" x-text="formatStatValue('daily_gross')"></p>
                                </div>

                                {{-- Margin Ratio --}}
                                <div class="flex flex-col gap-1">
                                    <div class="flex items-center gap-2 text-white/60">
                                        <x-feathericon-percent class="h-4 w-4" />
                                        <span class="text-xs uppercase tracking-wide font-medium" style="font-family: 'Space Grotesk', sans-serif;">Margin Ratio</span>
                                    </div>
                                    <p class="text-lg font-bold" :class="getStatClass('margin_ratio')" style="font-family: 'JetBrains Mono', monospace; font-feature-settings: 'tnum', 'lnum';" x-text="formatStatValue('margin_ratio')"></p>
                                </div>

                                {{-- Drawdown --}}
                                <div class="flex flex-col gap-1">
                                    <div class="flex items-center gap-2 text-white/60">
                                        <x-feathericon-trending-down class="h-4 w-4" />
                                        <span class="text-xs uppercase tracking-wide font-medium" style="font-family: 'Space Grotesk', sans-serif;">Drawdown</span>
                                    </div>
                                    <p class="text-lg font-bold" :class="getStatClass('drawdown')" style="font-family: 'JetBrains Mono', monospace; font-feature-settings: 'tnum', 'lnum';" x-text="formatStatValue('drawdown')"></p>
                                </div>

                                {{-- Clean Revenue --}}
                                <div class="flex flex-col gap-1">
                                    <div class="flex items-center gap-2 text-white/60">
                                        <x-feathericon-dollar-sign class="h-4 w-4" />
                                        <span class="text-xs uppercase tracking-wide font-medium" style="font-family: 'Space Grotesk', sans-serif;">Clean Revenue</span>
                                    </div>
                                    <p class="text-lg font-bold" :class="getStatClass('clean_revenue')" style="font-family: 'JetBrains Mono', monospace; font-feature-settings: 'tnum', 'lnum';" x-text="formatStatValue('clean_revenue')"></p>
                                </div>

                                {{-- Avg Variation --}}
                                <div class="flex flex-col gap-1">
                                    <div class="flex items-center gap-2 text-white/60">
                                        <x-feathericon-activity class="h-4 w-4" />
                                        <span class="text-xs uppercase tracking-wide font-medium" style="font-family: 'Space Grotesk', sans-serif;">Avg Variation</span>
                                    </div>
                                    <p class="text-lg font-bold" :class="getStatClass('avg_variation')" style="font-family: 'JetBrains Mono', monospace; font-feature-settings: 'tnum', 'lnum';" x-text="formatStatValue('avg_variation')"></p>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- LONG Positions --}}
                    <div x-show="longPositions.length > 0" class="mb-6">
                        <div class="flex items-center gap-2 mb-3">
                            <div class="h-1 w-1 rounded-full bg-emerald-400"></div>
                            <h2 class="text-sm font-bold text-emerald-400 uppercase tracking-wider" style="font-family: 'Space Grotesk', sans-serif;">Long Positions</h2>
                            <div class="flex-1 h-px bg-gradient-to-r from-emerald-400/20 to-transparent"></div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-6 gap-4">
                            <template x-for="position in longPositions" :key="position.id">
                                <div x-data="positionCard(position)">
                                    <x-dashboard.position-card-dynamic />
                                </div>
                            </template>
                        </div>
                    </div>

                    {{-- SHORT Positions --}}
                    <div x-show="shortPositions.length > 0" class="mb-6">
                        <div class="flex items-center gap-2 mb-3">
                            <div class="h-1 w-1 rounded-full bg-{{ theme('primary.base') }}"></div>
                            <h2 class="text-sm font-bold text-{{ theme('primary.text') }} uppercase tracking-wider" style="font-family: 'Space Grotesk', sans-serif;">Short Positions</h2>
                            <div class="flex-1 h-px bg-gradient-to-r from-{{ theme('primary.base') }}/20 to-transparent"></div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-6 gap-4">
                            <template x-for="position in shortPositions" :key="position.id">
                                <div x-data="positionCard(position)">
                                    <x-dashboard.position-card-dynamic />
                                </div>
                            </template>
                        </div>
                    </div>

                    {{-- Empty State --}}
                    <div x-show="longPositions.length === 0 && shortPositions.length === 0" class="text-center py-12">
                        <div class="inline-grid h-16 w-16 place-items-center rounded-full bg-white/5 border border-white/10 mb-4">
                            <x-feathericon-activity class="h-8 w-8 text-white/40" />
                        </div>
                        <h3 class="text-lg font-semibold text-white mb-2">No Active Positions</h3>
                        <p class="text-sm text-white/60">Start trading to see your positions here</p>
                    </div>
                </div>
            </div>

            {{-- Accounts Section --}}
            <div x-show="activeSection === 'accounts'" x-transition:enter.duration.300ms style="display: none;">
                <h1 class="text-3xl font-bold text-white mb-2">Accounts</h1>
                <p class="text-white/60">Manage your trading accounts</p>

                <div class="mt-8">
                    <div class="rounded-lg border border-white/10 bg-white/5 p-6">
                        <p class="text-white/80">Accounts content coming soon...</p>
                    </div>
                </div>
            </div>

            {{-- Analytics Section --}}
            <div x-show="activeSection === 'analytics'" x-transition:enter.duration.300ms style="display: none;">
                <h1 class="text-3xl font-bold text-white mb-2">Analytics</h1>
                <p class="text-white/60">View your trading analytics</p>

                <div class="mt-8">
                    <div class="rounded-lg border border-white/10 bg-white/5 p-6">
                        <p class="text-white/80">Analytics content coming soon...</p>
                    </div>
                </div>
            </div>

            {{-- Profile Section --}}
            <div x-show="activeSection === 'profile'" x-transition:enter.duration.300ms style="display: none;">
                <x-dashboard.section-header
                    title="My Profile"
                    subtitle="Update your personal information and password"
                    icon="user"
                />

                <form
                    @submit.prevent="submitProfile"
                    class="max-w-2xl"
                    novalidate
                >
                    {{-- Personal Information Section --}}
                    <div class="mb-6">
                        <h3 class="text-base font-semibold text-white mb-2">Personal Information</h3>
                        <p class="text-sm text-white/60 mb-6">Update your account details</p>
                    </div>

                    {{-- Name Field --}}
                    <div class="mb-6">
                        <label for="name" class="block text-sm font-medium text-white/80 mb-2">
                            Name <span class="text-red-500" aria-hidden="true">*</span>
                        </label>
                        <x-ui.input
                            id="name"
                            name="name"
                            type="text"
                            leading-icon="user"
                            x-model="profileForm.name"
                        />
                        <p x-show="profileErrors.name" x-text="profileErrors.name" class="mt-2 text-xs text-red-500"></p>
                    </div>

                    {{-- Email Field --}}
                    <div class="mb-6">
                        <label for="email" class="block text-sm font-medium text-white/80 mb-2">
                            Email <span class="text-red-500" aria-hidden="true">*</span>
                        </label>
                        <x-ui.input
                            id="email"
                            name="email"
                            type="email"
                            leading-icon="mail"
                            notice="Your email is also where we send you important notifications when needed"
                            x-model="profileForm.email"
                        />
                        <p x-show="profileErrors.email" x-text="profileErrors.email" class="mt-2 text-xs text-red-500"></p>
                    </div>

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
                                icon="mail"
                                title="Email"
                                description="Receive notifications via email"
                                suppress-error="true"
                                x-model="profileForm.notification_channels"
                            />

                            {{-- Pushover Notifications --}}
                            <x-ui.checkbox
                                id="notification_pushover"
                                name="notification_channels[]"
                                value="pushover"
                                icon="smartphone"
                                title="Pushover"
                                description="Receive push notifications on your devices"
                                suppress-error="true"
                                x-model="profileForm.notification_channels"
                            />
                        </div>

                        <p x-show="profileErrors.notification_channels" x-text="profileErrors.notification_channels" class="mt-3 text-xs text-red-500"></p>

                        {{-- Pushover Key Field --}}
                        <div class="mt-6">
                            <label for="pushover_key" class="block text-sm font-medium text-white/80 mb-2">
                                Pushover API Key
                            </label>
                            <div class="space-y-2 [&_svg]:stroke-current [&_svg]:text-current">
                                <div class="relative">
                                    <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-white/60">
                                        <x-feathericon-key class="h-4 w-4"/>
                                    </span>
                                    <input
                                        id="pushover_key"
                                        name="pushover_key"
                                        type="text"
                                        placeholder="Enter your Pushover user/group key"
                                        x-model="profileForm.pushover_key"
                                        x-bind:disabled="!isPushoverEnabled"
                                        x-bind:class="!isPushoverEnabled ? 'opacity-60 cursor-not-allowed' : ''"
                                        class="w-full rounded-lg bg-[{{ theme('form.bg') }}] text-{{ theme('form.text') }} placeholder-{{ theme('form.placeholder') }} outline-none transition border py-0 h-12 text-base pl-10 pr-4 border-{{ theme('form.border') }} focus:ring-2 focus:ring-{{ theme('form.border') }} focus:border-{{ theme('form.border_focus') }}"
                                    />
                                </div>
                                <p class="text-xs text-yellow-400/80 flex items-center gap-1.5">
                                    <x-feathericon-alert-circle class="h-3 w-3 shrink-0"/>
                                    <span>Required if you want to receive Pushover notifications</span>
                                </p>
                            </div>
                            <p x-show="profileErrors.pushover_key" x-text="profileErrors.pushover_key" class="mt-2 text-xs text-red-500"></p>
                            <div class="mt-4 pt-2">
                                <x-button
                                    variant="secondary"
                                    size="sm"
                                    @click="testPushover"
                                    x-bind:disabled="!canTestPushover"
                                    x-bind:class="!canTestPushover ? 'opacity-60 cursor-not-allowed' : 'cursor-pointer'"
                                >
                                    <x-feathericon-arrow-right class="h-4 w-4" x-show="!testingPushover"/>
                                    <x-feathericon-loader class="h-4 w-4 animate-spin" x-show="testingPushover" style="display: none;"/>
                                    <span x-text="testingPushover ? 'Sending...' : 'Test Pushover'"></span>
                                </x-button>
                            </div>
                            <p x-show="profileErrors.pushover_key" x-text="profileErrors.pushover_key" class="mt-2 text-xs text-red-500"></p>
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
                                x-model="profileForm.password"
                            />
                            <p x-show="profileErrors.password" x-text="profileErrors.password" class="mt-2 text-xs text-red-500"></p>
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
                                x-model="profileForm.password_confirmation"
                            />
                        </div>
                    </div>

                    {{-- Submit Button --}}
                    <div class="mt-8">
                        <x-button
                            variant="primary"
                            type="submit"
                            x-bind:disabled="submittingProfile"
                            x-bind:class="submittingProfile ? 'opacity-60 cursor-not-allowed' : 'cursor-pointer'"
                        >
                            <x-feathericon-save class="h-4 w-4" x-show="!submittingProfile"/>
                            <x-feathericon-loader class="h-4 w-4 animate-spin" x-show="submittingProfile" style="display: none;"/>
                            <span x-text="submittingProfile ? 'Updating...' : 'Update Profile'"></span>
                        </x-button>
                    </div>
                </form>
            </div>
        </div>

    {{-- Alpine Dashboard App Component --}}
    <x-slot:scripts>
        @vite(['resources/js/dashboard-charts.js', 'resources/js/dashboard-api.js'])

        <script>
            function dashboardApp() {
                return {
                    activeSection: 'dashboard',
                    activeNavItem: 'dashboard',
                    loading: true,
                    dashboardData: null,
                    longPositions: [],
                    shortPositions: [],
                    isPageRefresh: true, // Track if this is initial page load (F5)

                    async init() {
                        console.log('🎯 Alpine Dashboard App initialized');

                        // Detect if this is a page refresh (F5) or SPA navigation
                        // On first load, performance.navigation.type === 1 for reload
                        // Or use sessionStorage to track if we've loaded before
                        const hasLoadedBefore = sessionStorage.getItem('dashboard_loaded');
                        this.isPageRefresh = !hasLoadedBefore;

                        if (this.isPageRefresh) {
                            console.log('🔄 Page refresh detected (F5)');
                            sessionStorage.setItem('dashboard_loaded', 'true');
                        } else {
                            console.log('📱 SPA navigation (using cache)');
                        }

                        // Read initial section from URL
                        const path = window.location.pathname;
                        const match = path.match(/\/dashboard\/(.+)/);
                        if (match) {
                            this.activeSection = match[1];
                            this.activeNavItem = match[1];
                        } else {
                            this.activeSection = 'dashboard';
                            this.activeNavItem = 'dashboard';
                        }

                        // Handle browser back/forward
                        window.addEventListener('popstate', (e) => {
                            if (e.state && e.state.section) {
                                this.activeSection = e.state.section;
                            }
                        });

                        // Trigger initial indicator update
                        this.$nextTick(() => {
                            window.dispatchEvent(new CustomEvent('dashboard-ready', {
                                detail: { activeSection: this.activeNavItem }
                            }));
                        });

                        // Watch for pushover checkbox changes to clear key when unchecked
                        this.$watch('profileForm.notification_channels', (newChannels) => {
                            if (!newChannels.includes('pushover')) {
                                this.profileForm.pushover_key = '';
                            }
                        });

                        // Fetch dashboard data
                        await this.loadDashboardData();
                    },

                    async loadDashboardData() {
                        try {
                            this.loading = true;
                            console.log('📡 Fetching dashboard data...');

                            // Build URL with refresh flag if this is a page refresh (F5)
                            const url = new URL('/api/dashboard/data', window.location.origin);
                            if (this.isPageRefresh) {
                                url.searchParams.set('refresh', 'true');
                                console.log('🔄 Adding refresh=true flag');
                            }

                            // Fetch data from API
                            const response = await fetch(url, {
                                method: 'GET',
                                headers: {
                                    'Accept': 'application/json',
                                    'Content-Type': 'application/json',
                                    'X-Requested-With': 'XMLHttpRequest',
                                },
                                credentials: 'same-origin',
                            });

                            // After first load, subsequent calls will use cache
                            this.isPageRefresh = false;

                            console.log('📡 Response status:', response.status);

                            if (response.status === 401) {
                                console.log('🔒 Unauthorized - redirecting to login');
                                window.location.href = '/login?redirect=' + encodeURIComponent(window.location.pathname);
                                return;
                            }

                            if (!response.ok) {
                                throw new Error(`HTTP error! status: ${response.status}`);
                            }

                            const result = await response.json();
                            console.log('📦 API Response:', result);

                            if (!result.success) {
                                throw new Error(result.message || 'Failed to fetch dashboard data');
                            }

                            const data = result.data;
                            this.dashboardData = data;
                            this.longPositions = data.positions?.long || [];
                            this.shortPositions = data.positions?.short || [];

                            console.log('✅ Dashboard data loaded');
                            console.log('  Data structure:', data);
                            console.log('  Positions object:', data.positions);
                            console.log('  Long positions array:', this.longPositions);
                            console.log('  Long positions count:', this.longPositions.length);
                            console.log('  Short positions array:', this.shortPositions);
                            console.log('  Short positions count:', this.shortPositions.length);

                            // Log each position details
                            console.group('📋 LONG Positions Details');
                            this.longPositions.forEach((pos, idx) => {
                                console.log(`  [${idx}] ${pos.token} (${pos.id}):`, {
                                    name: pos.name,
                                    position: pos.position,
                                    leverage: pos.leverage,
                                    price: pos.mark_price,
                                    hasChartData: !!pos.chart_data,
                                    chartTicks: pos.chart_data?.labels?.length
                                });
                            });
                            console.groupEnd();

                            console.group('📋 SHORT Positions Details');
                            this.shortPositions.forEach((pos, idx) => {
                                console.log(`  [${idx}] ${pos.token} (${pos.id}):`, {
                                    name: pos.name,
                                    position: pos.position,
                                    leverage: pos.leverage,
                                    price: pos.mark_price,
                                    hasChartData: !!pos.chart_data,
                                    chartTicks: pos.chart_data?.labels?.length
                                });
                            });
                            console.groupEnd();
                        } catch (error) {
                            console.error('❌ Failed to load dashboard data:', error);
                            this.longPositions = [];
                            this.shortPositions = [];
                        } finally {
                            this.loading = false;
                        }
                    },

                    navigateTo(section) {
                        console.log('🖱️ Navigating to:', section);
                        this.activeSection = section;
                        this.activeNavItem = section;

                        const url = section === 'dashboard'
                            ? '/dashboard'
                            : `/dashboard/${section}`;

                        history.pushState({ section }, '', url);
                    },

                    formatStatValue(statKey) {
                        if (!this.dashboardData?.global_stats) return '-';

                        const value = this.dashboardData.global_stats[statKey];
                        if (value === null || value === undefined) return '-';

                        const formattedValue = new Intl.NumberFormat('en-US', {
                            minimumFractionDigits: 2,
                            maximumFractionDigits: 2
                        }).format(Math.abs(value));

                        const sign = value < 0 ? '-' : '';

                        // Add appropriate suffix based on stat type
                        let suffix = '';
                        if (statKey.includes('revenue') || statKey.includes('gross')) {
                            suffix = ' USDT';
                        } else if (statKey.includes('ratio') || statKey.includes('drawdown') || statKey.includes('variation')) {
                            suffix = '%';
                        }

                        return sign + formattedValue + suffix;
                    },

                    getStatClass(statKey) {
                        if (!this.dashboardData?.global_stats) return 'text-white';

                        const value = this.dashboardData.global_stats[statKey];
                        if (value === null || value === undefined) return 'text-white';

                        // Force white for currency stats (revenue)
                        if (statKey.includes('revenue') || statKey.includes('gross')) return 'text-white';

                        // Color based on value for percentage stats
                        return value < 0 ? 'text-red-400' : 'text-emerald-400';
                    },

                    // Profile Form State
                    profileForm: {
                        name: '{{ auth()->user()->name }}',
                        email: '{{ auth()->user()->email }}',
                        notification_channels: @json(collect(auth()->user()->notification_channels)->map(function($channel) {
                            return str_contains($channel, 'Pushover') ? 'pushover' : $channel;
                        })->toArray()),
                        pushover_key: '{{ auth()->user()->pushover_key }}',
                        password: '',
                        password_confirmation: ''
                    },
                    profileErrors: {},
                    submittingProfile: false,
                    testingPushover: false,

                    // Computed: Is Pushover checkbox checked?
                    get isPushoverEnabled() {
                        return this.profileForm.notification_channels.includes('pushover');
                    },

                    // Computed: Can test Pushover?
                    get canTestPushover() {
                        return this.isPushoverEnabled &&
                               this.profileForm.pushover_key.trim() !== '' &&
                               !this.testingPushover;
                    },

                    // Test Pushover notification
                    async testPushover() {
                        this.testingPushover = true;

                        try {
                            const response = await fetch('/profile/test-pushover', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                                    'Accept': 'application/json',
                                    'X-Requested-With': 'XMLHttpRequest',
                                },
                                credentials: 'same-origin',
                                body: JSON.stringify({
                                    pushover_key: this.profileForm.pushover_key
                                })
                            });

                            const data = await response.json();

                            if (data.success) {
                                this.showNotification(data.message, 'success');
                            } else {
                                this.showNotification(data.message, 'error');
                            }
                        } catch (error) {
                            console.error('❌ Failed to test Pushover:', error);
                            this.showNotification('Failed to send test notification', 'error');
                        } finally {
                            this.testingPushover = false;

                            // Re-render Feather icons after state change
                            this.$nextTick(() => {
                                if (typeof feather !== 'undefined') {
                                    feather.replace();
                                }
                            });
                        }
                    },

                    // Submit profile form
                    async submitProfile() {
                        this.submittingProfile = true;
                        this.profileErrors = {};

                        try {
                            const response = await fetch('/profile', {
                                method: 'PUT',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                                    'Accept': 'application/json',
                                    'X-Requested-With': 'XMLHttpRequest',
                                },
                                credentials: 'same-origin',
                                body: JSON.stringify(this.profileForm)
                            });

                            const data = await response.json();

                            if (response.ok && data.success) {
                                this.showNotification(data.message || 'Profile updated successfully!', 'success');

                                // Clear password fields
                                this.profileForm.password = '';
                                this.profileForm.password_confirmation = '';

                                // If password was changed, user will be redirected to login
                                if (data.redirect) {
                                    setTimeout(() => {
                                        window.location.href = data.redirect;
                                    }, 1500);
                                }
                            } else if (response.status === 422) {
                                // Validation errors
                                this.profileErrors = data.errors || {};

                                // Get first validation error message
                                let firstError = 'Please fix the validation errors';
                                if (data.errors && Object.keys(data.errors).length > 0) {
                                    const firstKey = Object.keys(data.errors)[0];
                                    const firstErrorArray = data.errors[firstKey];
                                    firstError = Array.isArray(firstErrorArray) ? firstErrorArray[0] : firstErrorArray;
                                }

                                this.showNotification(firstError, 'error');
                            } else {
                                throw new Error(data.message || 'Failed to update profile');
                            }
                        } catch (error) {
                            console.error('❌ Failed to update profile:', error);
                            this.showNotification(error.message || 'Failed to update profile', 'error');
                        } finally {
                            this.submittingProfile = false;

                            // Re-render Feather icons
                            this.$nextTick(() => {
                                if (typeof feather !== 'undefined') {
                                    feather.replace();
                                }
                            });
                        }
                    },

                    // Show notification (simple toast)
                    showNotification(message, type = 'info') {
                        // Use existing toast if available
                        if (typeof window.showToast === 'function') {
                            window.showToast(message, type);
                        } else {
                            // Fallback to alert
                            alert(message);
                        }
                    }
                };
            }

            // Make it globally available
            window.dashboardApp = dashboardApp;
        </script>
    </x-slot:scripts>
</x-layouts.dashboard>
