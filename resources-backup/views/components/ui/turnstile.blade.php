{{-- resources/views/components/ui/turnstile.blade.php --}}
@php
    /**
     * Wrapper around <x-turnstile> that:
     *  - Forwards attributes to the vendor component.
     *  - Shows an inline error message from $errors (server-side).
     *
     * Props:
     *  - id: required for programmatic execute (must match /^[a-zA-Z_][a-zA-Z0-9_]*$/).
     *  - error-key: validation key to read (default 'cf-turnstile-response').
     *  - You can pass any data-* accepted by Turnstile (data-theme, data-size, data-callback, ...).
     */

    $errorKey = $attributes->get('error-key', 'cf-turnstile-response');

    $normalize = function ($key) {
        if (!is_string($key) || $key === '') return null;
        if (str_contains($key, '[')) {
            $key = preg_replace('/\[(.*?)\]/', '.$1', $key);
            $key = rtrim($key, '.');
            $key = str_replace('..', '.', $key);
        }
        return $key;
    };

    $errKey = $normalize($errorKey);
    $errorMessage = (isset($errors) && $errKey) ? $errors->first($errKey) : null;

    // Default id for the widget (must start with letter/_)
    $id = $attributes->get('id', 'turnstile_widget');
@endphp

{{-- Apply the id to the wrapper div since vendor component doesn't render it --}}
<div id="{{ $id }}" {{ $attributes->except(['id', 'error-key'])->merge(['class' => 'space-y-2']) }}>
    <x-turnstile
        {{ $attributes->only(['data-size', 'data-theme', 'data-action', 'data-cdata', 'data-callback', 'data-error-callback', 'data-expired-callback', 'data-timeout-callback', 'data-before-interactive-callback', 'data-after-interactive-callback', 'data-unsupported-callback']) }}
        data-theme="{{ $attributes->get('data-theme', 'dark') }}"
    />

    @if($errorMessage)
        <p class="text-xs text-red-400" role="alert" aria-live="polite">
            {{ $errorMessage }}
        </p>
    @endif
</div>
