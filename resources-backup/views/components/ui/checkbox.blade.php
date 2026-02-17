{{-- resources/views/components/ui/checkbox.blade.php --}}
@php
    /**
     * <x-ui.checkbox>
     * - Large checkbox component matching the input design system
     * - Supports icon, title, description, and error states
     *
     * Props:
     * - id, name, value (checkbox value)
     * - checked (boolean)
     * - icon (Feather icon name)
     * - title (main label text)
     * - description (helper text below title)
     * - invalid, required, disabled
     * - error-key (override the validation key for $errors lookup)
     */

    // ----- Extract basics
    $id          = $attributes->get('id');
    $name        = $attributes->get('name');
    $value       = $attributes->get('value');
    $title       = $attributes->get('title', 'Checkbox');
    $description = $attributes->get('description');
    $icon        = $attributes->get('icon');

    // Booleans
    $checkedRaw  = $attributes->get('checked', false);
    $checked     = is_bool($checkedRaw) ? $checkedRaw : filter_var($checkedRaw, FILTER_VALIDATE_BOOLEAN);
    $invalidRaw  = $attributes->get('invalid', false);
    $invalidAttr = is_bool($invalidRaw) ? $invalidRaw : filter_var($invalidRaw, FILTER_VALIDATE_BOOLEAN);
    $requiredRaw = $attributes->get('required', false);
    $required    = is_bool($requiredRaw) ? $requiredRaw : filter_var($requiredRaw, FILTER_VALIDATE_BOOLEAN);
    $disabledRaw = $attributes->get('disabled', false);
    $disabled    = is_bool($disabledRaw) ? $disabledRaw : filter_var($disabledRaw, FILTER_VALIDATE_BOOLEAN);
    $suppressErrorRaw = $attributes->get('suppress-error', false);
    $suppressError = is_bool($suppressErrorRaw) ? $suppressErrorRaw : filter_var($suppressErrorRaw, FILTER_VALIDATE_BOOLEAN);

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
    $primaryColors = theme_map_color(theme('primary.base'));
    $errorColors = theme_map_color(theme('error.base'));

    // Checkbox styling - using appearance-none to enable rounded corners (theme-aware)
    $formBg = theme('form.bg');
    $checkboxBase = 'appearance-none shrink-0 h-6 w-6 rounded-lg border transition-all cursor-pointer';
    $checkboxNormal = 'border-white/10 hover:border-white/20 focus:ring-2 ' . $primaryColors['border'] . '/30';
    $checkboxError = $errorColors['border'] . ' focus:ring-2 ' . $errorColors['border'] . '/30';
    $checkboxState = $invalid ? $checkboxError : $checkboxNormal;
    $checkboxClass = trim("$checkboxBase $checkboxState");
    $checkboxBgStyle = "background-color: {$formBg}";

    // Checkmark styling
    $checkmarkClass = 'pointer-events-none absolute left-0 top-0 h-6 w-6 flex items-center justify-center text-white opacity-0 transition-opacity';
    $checkmarkCheckedClass = 'peer-checked:opacity-100';

    // Label container styling
    $labelFlags = $disabled ? 'opacity-60 cursor-not-allowed' : 'cursor-pointer';

    // ARIA
    $errId = ($errorMessage && $id) ? "{$id}_error" : null;
    $describedBy = $errId;

    // Pass-through attributes to the <input>
    $passthrough = $attributes->except([
        'id','name','value','checked','title','description','icon',
        'invalid','required','disabled','class','error-key','suppress-error'
    ]);
@endphp

@php
    // Get RGB value for checked state (convert Tailwind class to RGB)
    $primaryBase = theme('primary.base');
    $checkedBgColor = match($primaryBase) {
        'red-400' => 'rgb(248, 113, 113)',
        'red-500' => 'rgb(239, 68, 68)',
        'red-600' => 'rgb(220, 38, 38)',
        'red-700' => 'rgb(185, 28, 28)',
        'blue-400' => 'rgb(96, 165, 250)',
        'blue-500' => 'rgb(59, 130, 246)',
        'blue-600' => 'rgb(37, 99, 235)',
        'emerald-400' => 'rgb(52, 211, 153)',
        'emerald-500' => 'rgb(16, 185, 129)',
        'pink-500' => 'rgb(236, 72, 153)',
        'pink-600' => 'rgb(219, 39, 119)',
        'purple-500' => 'rgb(168, 85, 247)',
        'purple-600' => 'rgb(147, 51, 234)',
        'orange-500' => 'rgb(249, 115, 22)',
        'orange-600' => 'rgb(234, 88, 12)',
        default => 'rgb(220, 38, 38)', // fallback to red-600
    };
@endphp

<div class="space-y-2">
    <style>
        #{{ $id }}:checked {
            background-color: {{ $checkedBgColor }} !important;
            border-color: {{ $checkedBgColor }} !important;
        }
    </style>
    <div class="flex items-start gap-3">
        {{-- Checkbox wrapper with checkmark --}}
        <div class="relative shrink-0">
            <input
                type="checkbox"
                @if($id) id="{{ $id }}" @endif
                @if($name) name="{{ $name }}" @endif
                @if($value) value="{{ $value }}" @endif
                @if($checked) checked @endif
                @if($required) required @endif
                @if($disabled) disabled @endif
                aria-invalid="{{ $invalid ? 'true' : 'false' }}"
                @if($describedBy) aria-describedby="{{ $describedBy }}" @endif
                style="{{ $checkboxBgStyle }}"
                {{ $passthrough->merge(['class' => $checkboxClass . ' peer']) }}
            />
            {{-- Custom checkmark icon --}}
            <div class="{{ $checkmarkClass }} {{ $checkmarkCheckedClass }}">
                <svg class="h-4 w-4" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M13 4L6 11L3 8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
        </div>

        {{-- Content --}}
        <div class="flex-1 pt-0.5">
            <label for="{{ $id }}" class="group {{ $labelFlags }} inline-block">
                <div class="flex items-center gap-2 mb-1">
                    @if ($icon)
                        <x-feathericon-{{ $icon }}
                           class="h-4 w-4 shrink-0 {{ $invalid ? $errorColors['text'] : 'text-white/60 group-hover:text-white/80' }} transition-colors"/>
                    @endif
                    <span class="text-sm font-medium {{ $invalid ? $errorColors['text'] : 'text-white/80 group-hover:text-white' }} transition-colors">
                        {{ $title }}
                    </span>
                </div>
            </label>
            @if ($description)
                <p class="text-xs {{ $invalid ? $errorColors['text'] . '/70' : 'text-white/50' }} leading-relaxed">
                    {{ $description }}
                </p>
            @endif
        </div>
    </div>

    {{-- Inline error message (single, from $errors) --}}
    @if ($errorMessage && $id && !$suppressError)
        <p id="{{ $errId }}" class="text-xs {{ $errorColors['text'] }} pl-9" role="alert" aria-live="polite">
            {{ $errorMessage }}
        </p>
    @endif
</div>
