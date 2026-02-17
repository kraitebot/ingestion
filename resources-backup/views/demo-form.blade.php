{{-- resources/views/demo-form.blade.php --}}
<x-layouts.app
    title="UI Demo — Buttons & Inputs"
    metaDescription="Demo form to evaluate x-ui.input and x-ui.button"
>
    <x-slot:bodyTop>
        <div aria-hidden="true"
             class="fixed inset-0 -z-10 bg-[radial-gradient(circle_at_1px_1px,rgba(255,255,255,0.06)_1px,transparent_0)] [background-size:24px_24px]"></div>
    </x-slot:bodyTop>

    <x-slot:navbar>
        <x-landing.layout.navbar :show-login="true" :login-disabled="true" />
    </x-slot:navbar>

    <div class="mx-auto max-w-3xl px-6">
        @include('components.landing.sections.forms.demo-form')

        <div class="mt-12 flex justify-center">
            <img
                src="{{ asset('images/logo.svg') }}"
                alt="Martingalian"
                class="h-32 w-auto"
            />
        </div>
    </div>

    <x-slot:footer>
        <x-landing.layout.footer />
    </x-slot:footer>

    <x-slot:scripts>
    </x-slot:scripts>
</x-layouts.app>
