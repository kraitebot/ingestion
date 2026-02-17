{{-- resources/views/components/ui/input.blade.php --}}
@php
    /**
     * <x-ui.input>
     * - Plain input with optional help and leading/trailing Feather icons.
     * - Uses the default $errors bag (or explicit :invalid) to style and show one inline error.
     *
     * Props:
     * - id, name, type, placeholder, help, value, autocomplete
     * - leading-icon, trailing-icon
     * - invalid, required, disabled, readonly
     * - error-key (override the validation key for $errors lookup)
     * - notice (informational message below the field)
     */

    // ----- Extract basics
    $id           = $attributes->get('id');
    $name         = $attributes->get('name');
    $type         = $attributes->get('type', 'text');
    $placeholder  = $attributes->get('placeholder');
    $help         = $attributes->get('help');
    $notice       = $attributes->get('notice');
    $value        = $attributes->get('value');
    $autocomplete = $attributes->get('autocomplete');

    // Icons
    $leadingIcon  = $attributes->get('leading-icon');   // e.g. "mail"
    $trailingIcon = $attributes->get('trailing-icon');  // e.g. "check-circle"

    // Booleans
    $invalidRaw   = $attributes->get('invalid', false);
    $invalidAttr  = is_bool($invalidRaw) ? $invalidRaw : filter_var($invalidRaw, FILTER_VALIDATE_BOOLEAN);
    $requiredRaw  = $attributes->get('required', false);
    $required     = is_bool($requiredRaw) ? $requiredRaw : filter_var($requiredRaw, FILTER_VALIDATE_BOOLEAN);
    $disabledRaw  = $attributes->get('disabled', false);
    $disabled     = is_bool($disabledRaw) ? $disabledRaw : filter_var($disabledRaw, FILTER_VALIDATE_BOOLEAN);
    $readonlyRaw  = $attributes->get('readonly', false);
    $readonly     = is_bool($readonlyRaw) ? $readonlyRaw : filter_var($readonlyRaw, FILTER_VALIDATE_BOOLEAN);

    // ----- Error lookup (default bag)
    $normalizeKey = function ($key) {
        if (!is_string($key) || $key === '') return null;
        if (str_contains($key, '[')) {
            // Convert "user[email]" -> "user.email"
            $key = preg_replace('/\[(.*?)\]/', '.$1', $key);
            $key = rtrim($key, '.');
            $key = str_replace('..', '.', $key);
        }
        return $key;
    };

    $errorKey     = $attributes->get('error-key', $name);
    $errorKeyDot  = $normalizeKey($errorKey);
    $errorMessage = (isset($errors) && $errorKeyDot) ? $errors->first($errorKeyDot) : null;

    // Final invalid state: explicit prop OR detected error
    $invalid = (bool) ($invalidAttr || $errorMessage);

    // Get theme-based colors using the helper
    $errorColors = theme_map_color(theme('error.base'));

    // ----- Classes
    $bgColor = theme('form.bg');
    $base   = "w-full rounded-lg text-white placeholder-white/40 outline-none transition border py-0";
    $size   = 'h-12 text-base pl-10 pr-10';  // NOTE: input text starts at pl-10
    $normal = 'border-white/10 focus:ring-2 focus:ring-white/10 focus:border-white/20';
    $error  = $errorColors['border'] . ' ring-2 ' . $errorColors['border'] . '/30 focus:' . $errorColors['border'] . ' focus:ring-' . $errorColors['border'] . '/30';
    $state  = $invalid ? $error : $normal;

    $flags   = trim(($disabled ? 'opacity-60 cursor-not-allowed ' : '') . ($readonly ? 'opacity-80 ' : ''));
    $classes = trim("$base $size $state $flags");
    $bgStyle = "background-color: {$bgColor}";

    // Icon color adapts to error state
    $iconColor = $invalid ? $errorColors['text'] : 'text-white/60';

    // ARIA
    $helpId  = ($help && $id) ? "{$id}_help" : null;
    $errId   = ($errorMessage && $id) ? "{$id}_error" : null;
    $describedByIds = array_filter([$errId, $helpId]);
    $describedBy = count($describedByIds) ? implode(' ', $describedByIds) : null;

    // Pass-through attributes to the <input>
    $passthrough = $attributes->except([
        'id','name','type','placeholder','help','notice','value',
        'invalid','required','disabled','readonly','autocomplete',
        'leading-icon','trailing-icon','class','error-key'
    ]);
@endphp

<div class="space-y-2 [&_svg]:stroke-current [&_svg]:text-current">
    <div class="relative">
        @if ($leadingIcon)
            <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 {{ $iconColor }} [&_svg]:h-4 [&_svg]:w-4">
                {!! svg("feathericon-{$leadingIcon}", 'h-4 w-4')->toHtml() !!}
            </span>
        @endif

        <input
            @if($id) id="{{ $id }}" @endif
            @if($name) name="{{ $name }}" @endif
            type="{{ $type }}"
            @if(!is_null($value)) value="{{ $value }}" @endif
            @if($placeholder) placeholder="{{ $placeholder }}" @endif
            @if($required) required @endif
            @if($disabled) disabled @endif
            @if($readonly) readonly @endif
            @if($autocomplete) autocomplete="{{ $autocomplete }}" @endif
            aria-invalid="{{ $invalid ? 'true' : 'false' }}"
            @if($describedBy) aria-describedby="{{ $describedBy }}" @endif
            style="{{ $bgStyle }}"
            {{ $passthrough->merge(['class' => $classes]) }}
        />

        @if ($trailingIcon)
            <span class="absolute right-3 top-1/2 -translate-y-1/2 {{ $iconColor }} [&_svg]:h-4 [&_svg]:w-4">
                {!! svg("feathericon-{$trailingIcon}", 'h-4 w-4')->toHtml() !!}
            </span>
        @endif
    </div>

    {{-- Inline error message (single, from $errors) --}}
    @if ($errorMessage && $id)
        <p id="{{ $errId }}" class="mt-1 text-xs {{ $errorColors['text'] }}" role="alert" aria-live="polite">
            {{ $errorMessage }}
        </p>
    @endif

    {{-- Help text (align with input text start when a leading icon exists) --}}
    @if ($help)
        <p @if($id) id="{{ $helpId }}" @endif class="text-xs text-white/60 {{ $leadingIcon ? 'pl-10' : '' }}">
            {{ $help }}
        </p>
    @endif

    {{-- Notice message (informational, yellow with icon) --}}
    @if ($notice)
        <p class="text-xs text-yellow-400/80 flex items-center gap-1.5">
            <x-feathericon-alert-circle class="h-3 w-3 shrink-0"/>
            <span>{{ $notice }}</span>
        </p>
    @endif
</div>
