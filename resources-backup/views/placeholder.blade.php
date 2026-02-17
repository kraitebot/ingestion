{{-- Placeholder Page --}}
<x-layouts.dashboard :title="$title . ' — ' . config('app.name')">
    {{-- Sidebar --}}
    <x-slot:sidebar>
        <x-ui.sidebar>
            <x-ui.sidebar.item route="dashboard.index" icon="activity" label="Dashboard" />
            <x-ui.sidebar.item route="accounts.index" icon="briefcase" label="Accounts" />
            <x-ui.sidebar.item route="analytics.index" icon="bar-chart-2" label="Analytics" />
            <x-ui.sidebar.item route="profile.index" icon="user" label="My Profile" />
        </x-ui.sidebar>
    </x-slot:sidebar>

    {{-- Main content --}}
    <div class="p-8">
        <h1 class="text-3xl font-bold text-white mb-2">{{ $title }}</h1>
        <p class="text-white/60">This page is coming soon...</p>

        <div class="mt-8">
            <div class="rounded-lg border border-white/10 bg-white/5 p-6">
                <p class="text-white/80">Content for {{ $title }} will be added here.</p>
            </div>
        </div>
    </div>
</x-layouts.dashboard>
