{{-- resources/views/auth/passwords/reset.blade.php --}}
<x-layouts.app
    :title="'Reset Password — ' . config('app.name')"
    meta-description="Create a new password for your {{ config('app.name') }} account."
>

    {{-- BODY TOP: dotted background --}}
    <x-slot:bodyTop>
        <div aria-hidden="true"
             class="fixed inset-0 -z-10 bg-[radial-gradient(circle_at_1px_1px,rgba(255,255,255,0.06)_1px,transparent_0)] [background-size:24px_24px]">
        </div>
    </x-slot:bodyTop>

    {{-- Navbar --}}
    <x-slot:navbar>
        <x-landing.layout.navbar
            :show-login="true"
            :show-subscribe="false"
        />
    </x-slot:navbar>

    <section class="px-4">
        <div class="mx-auto max-w-xl py-8">
            <div class="rounded-2xl border border-white/10 bg-white/5 p-6 md:p-8 shadow-[0_0_0_1px_rgba(255,255,255,0.02)_inset]">
                {{-- Header --}}
                <div class="mb-6 flex items-center gap-3">
                    <span class="inline-grid h-9 w-9 place-items-center rounded-full bg-red-500/10 border border-red-400/30">
                        <x-feathericon-shield class="h-5 w-5 text-red-300" aria-hidden="true"/>
                    </span>
                    <div>
                        <h1 class="text-xl font-semibold">Reset your password</h1>
                        <p class="text-sm text-white/60">Enter your new password below.</p>
                    </div>
                </div>

                {{-- Form --}}
                <form method="POST" action="{{ route('password.update') }}" novalidate>
                    @csrf

                    <input type="hidden" name="token" value="{{ $token }}">

                    <div class="mb-4">
                        <label for="email" class="block text-sm font-medium text-white/80 mb-2">
                            Email used to reset password <span class="text-red-400" aria-hidden="true">*</span>
                        </label>
                        <x-ui.input
                            id="email"
                            name="email"
                            type="email"
                            placeholder="you@domain.com"
                            autocomplete="email"
                            :invalid="$errors->has('email')"
                            value="{{ old('email') }}"
                            leading-icon="mail"
                            autofocus
                        />
                    </div>

                    <div class="mb-4">
                        <label for="password" class="block text-sm font-medium text-white/80 mb-2">
                            New Password <span class="text-red-400" aria-hidden="true">*</span>
                        </label>
                        <x-ui.input
                            id="password"
                            name="password"
                            type="password"
                            placeholder="••••••••"
                            autocomplete="new-password"
                            :invalid="$errors->has('password')"
                            leading-icon="lock"
                        />
                    </div>

                    <div class="mb-6">
                        <label for="password_confirmation" class="block text-sm font-medium text-white/80 mb-2">
                            Confirm New Password <span class="text-red-400" aria-hidden="true">*</span>
                        </label>
                        <x-ui.input
                            id="password_confirmation"
                            name="password_confirmation"
                            type="password"
                            placeholder="••••••••"
                            autocomplete="new-password"
                            leading-icon="lock"
                        />
                    </div>

                    <div class="space-y-3">
                        <x-ui.button type="submit" size="lg" full icon="check" class="font-semibold">
                            Reset Password
                        </x-ui.button>

                        @if (Route::has('login'))
                            <div class="text-center text-sm">
                                <span class="text-white/70">Remember your password?</span>
                                <a href="{{ route('login') }}" class="text-white hover:underline underline-offset-2">Sign in</a>
                            </div>
                        @endif
                    </div>
                </form>
            </div>

            <p class="mt-6 text-center text-xs text-white/50">
                Your password must be at least 8 characters long.
            </p>
        </div>
    </section>

    <x-slot:footer>
        <x-landing.layout.footer />
    </x-slot:footer>
</x-layouts.app>
