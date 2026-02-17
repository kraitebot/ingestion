{{-- resources/views/components/ui/global-warning.blade.php --}}
@php
    /**
     * <x-ui.global-warning>
     * - Global warning banner that displays critical user warnings
     * - Checks user behaviours JSON for warning conditions
     * - Only displays when user is authenticated
     */

    if (!auth()->check()) {
        return;
    }

    $user = auth()->user();
    $behaviours = $user->behaviours ?? [];

    // Check for bounced email warning
    $shouldAnnounceBouncedEmail = $behaviours['should_announce_bounced_email'] ?? false;

    // Only render if there's a warning to show
    if (!$shouldAnnounceBouncedEmail) {
        return;
    }
@endphp

<div class="bg-red-500/10 border-b border-red-400/30 backdrop-blur-sm">
    <div class="mx-auto max-w-7xl px-4 py-3">
        <div class="flex items-start gap-3">
            <span class="shrink-0 mt-0.5">
                <x-feathericon-alert-circle class="h-5 w-5 text-red-400"/>
            </span>
            <div class="flex-1 min-w-0">
                <p class="text-sm text-red-300">
                    <span class="font-semibold">Email notification issue:</span>
                    You are not able to receive email notifications. This can jeopardize important notifications for you. Please update your email as soon as possible.
                </p>
            </div>
        </div>
    </div>
</div>
