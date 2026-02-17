{{-- resources/views/auth/login.blade.php --}}
<x-layouts.app
    :title="'Login — ' . config('app.name')"
    meta-description="Sign in to continue to {{ config('app.name') }}."
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
            :show-login="false"
            :show-subscribe="true"
            :subscribe-disabled="true"
        />
    </x-slot:navbar>

    <section class="px-4">
        <div class="mx-auto max-w-xl py-8">
            <div class="rounded-2xl border border-white/10 bg-white/5 p-6 md:p-8 shadow-[0_0_0_1px_rgba(255,255,255,0.02)_inset]">
                {{-- Header --}}
                <div class="mb-6 flex items-center gap-3">
                    <span class="inline-grid h-9 w-9 place-items-center rounded-full bg-{{ theme('primary.bg') }} border border-{{ theme('primary.border') }}">
                        <x-feathericon-log-in class="h-5 w-5 text-{{ theme('primary.light') }}" aria-hidden="true"/>
                    </span>
                    <div>
                        <h1 class="text-xl font-semibold">Welcome back</h1>
                        <p class="text-sm text-white/60">Sign in to continue to {{ config('app.name') }}.</p>
                    </div>
                </div>

                {{-- Session status --}}
                @if (session('status'))
                    <div class="mb-4 rounded-lg border border-{{ theme('success.border') }} bg-{{ theme('success.bg') }} px-4 py-3 text-sm text-{{ theme('success.light') }}">
                        {{ session('status') }}
                    </div>
                @endif

                {{-- Turnstile validation error --}}
                @if ($errors->has('cf-turnstile-response'))
                    <div class="mb-4 rounded-lg border border-{{ theme('error.border') }} bg-{{ theme('error.bg') }} px-4 py-3 text-sm text-{{ theme('error.light') }}">
                        {{ $errors->first('cf-turnstile-response') }}
                    </div>
                @endif

                {{-- Form --}}
                <form id="login-form" method="POST" action="{{ route('login') }}" novalidate>
                    @csrf

                    <div class="mb-4">
                        <label for="email" class="block text-sm font-medium text-white/80 mb-2">
                            Email address <span class="text-{{ theme('error.text') }}" aria-hidden="true">*</span>
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
                        />
                    </div>

                    <div class="mb-2">
                        <label for="password" class="block text-sm font-medium text-white/80 mb-2">
                            Password <span class="text-{{ theme('error.text') }}" aria-hidden="true">*</span>
                        </label>
                        <x-ui.input
                            id="password"
                            name="password"
                            type="password"
                            placeholder="••••••••"
                            autocomplete="current-password"
                            :invalid="$errors->has('password')"
                            leading-icon="lock"
                        />
                    </div>

                    @if (Route::has('password.request'))
                        <div class="mb-4 text-right">
                            <a href="{{ route('password.request') }}" class="text-xs text-white/70 hover:text-white underline underline-offset-2">
                                Forgot your password?
                            </a>
                        </div>
                    @endif

                    <div class="mb-6">
                        <label for="remember" class="inline-flex items-center gap-2 select-none">
                            <input id="remember" type="checkbox" name="remember"
                                   class="h-4 w-4 rounded border-white/20 bg-white/5 text-{{ theme('primary.hover') }} focus:ring-{{ theme('primary.border') }}"
                                   {{ old('remember') ? 'checked' : '' }}>
                            <span class="text-sm text-white/80">Remember me</span>
                        </label>
                    </div>

                    <div class="space-y-3">
                        <x-ui.button id="login-submit" type="submit" size="lg" full icon="log-in" class="font-semibold">
                            Sign in
                        </x-ui.button>

                        @if (Route::has('register'))
                            <div class="text-center text-sm">
                                <span class="text-white/70">Don’t have an account?</span>
                                <a href="{{ route('register') }}" class="text-white hover:underline underline-offset-2">Subscribe</a>
                            </div>
                        @endif
                    </div>
                </form>
            </div>

            <p class="mt-6 text-center text-xs text-white/50">
                Protected by best practices. Never share your credentials.
            </p>
        </div>
    </section>

    <x-slot:footer>
        <x-landing.layout.footer />
    </x-slot:footer>
</x-layouts.app>
