{{-- resources/views/sections/forms/demo-form.blade.php --}}
@php
    // Server errors (default bag)
    $nameHasError    = $errors->has('name');
    $emailHasError   = $errors->has('email');
    $addressHasError = $errors->has('address');

    // Trailing icons (SSR hint only)
    $nameTrailing    = $nameHasError ? 'alert-circle'    : (old('name')    ? 'check-circle' : null);
    $emailTrailing   = $emailHasError ? 'alert-circle'   : (old('email')   ? 'check-circle' : null);
    $addressTrailing = $addressHasError ? 'alert-circle' : (old('address') ? 'check-circle' : null);
@endphp

<div class="rounded-xl border border-white/10 bg-white/5 p-6">
    <div class="text-lg font-semibold">UI Demo — Inputs & Buttons</div>
    <p class="mt-1 text-xs text-white/60">
        Three inputs with per-field action buttons and a submit. Validation errors are shown by the inputs.
    </p>

    @if (session('success'))
        <p class="mt-4 text-sm font-medium text-emerald-400">
            {{ session('success') }}
        </p>
    @endif

    <form id="demo_form" class="mt-6 space-y-4" action="{{ route('demo.form.store') }}" method="POST" novalidate>
        @csrf

        <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_12rem]">
            {{-- Row 1 — Full name --}}
            <div>
                <x-ui.input
                    id="demo_name"
                    name="name"
                    placeholder="Your full name"
                    leading-icon="user"
                    :trailing-icon="$nameTrailing"
                    :invalid="$nameHasError"
                    class="h-12"
                    value="{{ old('name') }}"
                    required
                    minlength="2"
                />
            </div>
            <div class="w-full sm:w-[12rem]">
                <x-ui.button
                    id="btn_check_name"
                    type="button"
                    full
                    size="md"
                    class="h-12 font-semibold"
                    icon="search"
                >
                    Check name
                </x-ui.button>
            </div>

            {{-- Row 2 — Email --}}
            <div>
                <x-ui.input
                    id="demo_email"
                    name="email"
                    type="email"
                    placeholder="you@domain.com"
                    autocomplete="email"
                    leading-icon="mail"
                    :trailing-icon="$emailTrailing"
                    :invalid="$emailHasError"
                    class="h-12"
                    value="{{ old('email') }}"
                    required
                />
            </div>
            <div class="w-full sm:w-[12rem]">
                <x-ui.button
                    id="btn_check_email"
                    type="button"
                    full
                    size="md"
                    class="h-12 font-semibold"
                    icon="search"
                >
                    Check availability
                </x-ui.button>
            </div>

            {{-- Row 3 — Address --}}
            <div>
                <x-ui.input
                    id="demo_address"
                    name="address"
                    placeholder="Street, number, city"
                    autocomplete="street-address"
                    leading-icon="map-pin"
                    :trailing-icon="$addressTrailing"
                    :invalid="$addressHasError"
                    class="h-12"
                    value="{{ old('address') }}"
                    required
                    minlength="6"
                />
            </div>
            <div class="w-full sm:w-[12rem]">
                <x-ui.button
                    id="btn_check_address"
                    type="button"
                    full
                    size="md"
                    class="h-12 font-semibold"
                    icon="search"
                >
                    Check address
                </x-ui.button>
            </div>

            {{-- Submit --}}
            <div class="pt-2">
                <x-ui.button
                    id="btn_submit"
                    type="submit"
                    size="md"
                    class="w-full sm:w-auto h-12 px-6 font-semibold"
                    icon="send"
                >
                    Submit
                </x-ui.button>
            </div>
        </div>
    </form>
</div>
