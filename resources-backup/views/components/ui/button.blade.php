{{-- resources/views/components/ui/button.blade.php --}}
@php
    /**
     * <x-ui.button>
     * - Plain button (or <a> when href is set).
     * - No rule-binding / no session logic — disabled state controlled only by `status="disabled"`.
     *
     * Props:
     * - href, type, status('active'|'disabled'), size('sm'|'md'|'lg'), theme('red'|'blue'|'green'|'purple'), full (bool)
     * - icon, icon-right
     */

    // Basics
    $href    = $attributes->get('href');
    $type    = $attributes->get('type');
    $status  = $attributes->get('status', 'active');  // 'active' | 'disabled'
    $size    = $attributes->get('size', 'md');        // 'sm' | 'md' | 'lg'
    $theme   = $attributes->get('theme', 'red');      // 'red' | 'blue' | 'green' | 'purple'
    $fullRaw = $attributes->get('full', false);
    $full    = is_bool($fullRaw) ? $fullRaw : filter_var($fullRaw, FILTER_VALIDATE_BOOLEAN);

    // Icons
    $iconLeft  = $attributes->get('icon');
    $iconRight = $attributes->get('icon-right');
    $iconInherit = '[&_svg]:text-current [&_svg]:stroke-current';

    // Size & padding
    $heightMap = ['sm' => 'h-10 text-[15px]', 'md' => 'h-12 text-base', 'lg' => 'h-14 text-[17px]'];
    $heightClasses = $heightMap[$size] ?? $heightMap['md'];

    $padDefault = ['sm' => ['pl'=>'pl-3','pr'=>'pr-3'], 'md' => ['pl'=>'pl-4','pr'=>'pr-4'], 'lg' => ['pl'=>'pl-5','pr'=>'pr-5']];
    $padLeft  = $iconLeft  ? 'pl-10' : ($padDefault[$size]['pl'] ?? 'pl-4');
    $padRight = $iconRight ? 'pr-10' : ($iconLeft ? 'pr-10' : ($padDefault[$size]['pr'] ?? 'pr-4'));
    $padClasses = trim("$padLeft $padRight");

    $width = $full ? 'w-full' : '';

    // Icon size
    $iconSizeMap = ['sm' => 'h-4 w-4', 'md' => 'h-4 w-4', 'lg' => 'h-5 w-5'];
    $iconSize = $iconSizeMap[$size] ?? $iconSizeMap['md'];

    // Theme palettes (using theme config)
    $primaryBase = theme('primary.base');
    $primaryHover = theme('primary.hover');
    $primaryBorder = theme('primary.border');
    $secondaryBase = theme('secondary.base');
    $secondaryHover = theme('secondary.hover');
    $secondaryBorder = theme('secondary.border');

    $themeColors = [
        'red' => "cursor-pointer border-{$primaryBorder} bg-gradient-to-br from-{$primaryBase}/[0.28] to-{$primaryHover}/[0.18] text-white hover:from-{$primaryBase}/[0.38] hover:to-{$primaryHover}/[0.28] hover:border-{$primaryBorder} focus-visible:ring-2 focus-visible:ring-{$primaryBorder} transition-all duration-200",
        'blue' => "cursor-pointer border-{$secondaryBorder} bg-gradient-to-br from-{$secondaryBase}/[0.28] to-{$secondaryHover}/[0.18] text-white hover:from-{$secondaryBase}/[0.38] hover:to-{$secondaryHover}/[0.28] hover:border-{$secondaryBorder} focus-visible:ring-2 focus-visible:ring-{$secondaryBorder} transition-all duration-200",
        'green' => 'cursor-pointer border-emerald-400/40 bg-gradient-to-br from-emerald-500/[0.28] to-emerald-600/[0.18] text-white hover:from-emerald-500/[0.38] hover:to-emerald-600/[0.28] hover:border-emerald-400/60 focus-visible:ring-2 focus-visible:ring-emerald-300 transition-all duration-200',
        'purple' => 'cursor-pointer border-purple-400/40 bg-gradient-to-br from-purple-500/[0.28] to-purple-600/[0.18] text-white hover:from-purple-500/[0.38] hover:to-purple-600/[0.28] hover:border-purple-400/60 focus-visible:ring-2 focus-visible:ring-purple-300 transition-all duration-200',
        'primary' => "cursor-pointer border-{$primaryBorder} bg-{$primaryBase} hover:bg-{$primaryHover} text-white shadow-sm transition-colors disabled:hover:bg-{$primaryBase}",
        'secondary' => "cursor-pointer border-{$secondaryBorder} bg-{$secondaryBase} hover:bg-{$secondaryHover} text-white shadow-sm transition-colors disabled:hover:bg-{$secondaryBase}",
    ];

    $activeColors   = $themeColors[$theme] ?? $themeColors['red'];
    $disabledColors = 'cursor-not-allowed opacity-80 border-gray-400/30 bg-gray-500/10 text-gray-400';

    $isDisabled = ($status === 'disabled');

    // Common classes
    $common  = "relative inline-flex shrink-0 items-center justify-center rounded-lg border transition-colors duration-200 whitespace-nowrap focus:outline-none $iconInherit $heightClasses $padClasses $width";
    $classes = $isDisabled ? "$common $disabledColors" : "$common $activeColors";

    // Pass-through
    $passthrough = $attributes->except(['href','type','status','size','theme','full','class','icon','icon-right']);

    // Icon renderer
    $renderIcon = function ($name, $pos) use ($iconSize) {
        if (! $name) return '';
        $sideClass = $pos === 'right' ? 'right-3' : 'left-3';
        $iconHtml = svg("feathericon-{$name}", $iconSize)->toHtml();
        return <<<HTML
            <span aria-hidden="true" class="pointer-events-none absolute {$sideClass} top-1/2 -translate-y-1/2 text-current">
                {$iconHtml}
            </span>
        HTML;
    };
@endphp

@if ($href)
    <a href="{{ $href }}"
       @if ($isDisabled) aria-disabled="true" tabindex="-1" role="button" onclick="return false;" @endif
       {!! $passthrough->merge(['class' => $classes]) !!}>
        {!! $renderIcon($iconLeft, 'left') !!}
        <span class="relative z-10 font-medium text-white">{{ $slot }}</span>
        {!! $renderIcon($iconRight, 'right') !!}
    </a>
@else
    <button type="{{ $type ?? 'button' }}"
            @if ($isDisabled) disabled aria-disabled="true" @endif
            {!! $passthrough->merge(['class' => $classes]) !!}>
        {!! $renderIcon($iconLeft, 'left') !!}
        <span class="relative z-10 font-medium text-white">{{ $slot }}</span>
        {!! $renderIcon($iconRight, 'right') !!}
    </button>
@endif
