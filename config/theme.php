<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Application Theme Configuration
    |--------------------------------------------------------------------------
    |
    | Centralized color theme for the entire application.
    | Primary color for active states, positive actions.
    | Navy/charcoal dark theme inspired by professional trading platforms.
    |
    */

    // Primary color (active states, positive actions)
    'primary' => [
        'base' => 'emerald-500',
        'hover' => 'emerald-600',
        'light' => 'emerald-400',
        'border' => 'emerald-500/30',
        'bg' => 'emerald-500/10',
        'text' => 'emerald-400',
    ],

    // Secondary color (utility actions, informational)
    'secondary' => [
        'base' => 'slate-500',
        'hover' => 'slate-600',
        'light' => 'slate-400',
        'border' => 'slate-500/30',
        'bg' => 'slate-500/10',
        'text' => 'slate-400',
    ],

    // Success color (same as primary for consistency)
    'success' => [
        'base' => 'emerald-500',
        'hover' => 'emerald-600',
        'light' => 'emerald-400',
        'border' => 'emerald-500/30',
        'bg' => 'emerald-500/10',
        'text' => 'emerald-400',
    ],

    // Error/Danger color (losses, negative)
    'error' => [
        'base' => 'red-500',
        'hover' => 'red-600',
        'light' => 'red-400',
        'border' => 'red-500/30',
        'bg' => 'red-500/10',
        'text' => 'red-400',
    ],

    // Warning color
    'warning' => [
        'base' => 'amber-500',
        'hover' => 'amber-600',
        'light' => 'amber-400',
        'border' => 'amber-500/30',
        'bg' => 'amber-500/10',
        'text' => 'amber-400',
    ],

    // Background colors (navy/charcoal dark theme)
    'background' => [
        'base' => '#0f1419',        // Main page background (dark navy)
        'elevated' => '#151b23',    // Dashboard body, elevated surfaces
        'sidebar' => '#1a2332',     // Sidebar background (slightly lighter)
        'card' => '#1c2432',        // Card backgrounds
        'modal' => '#151b23',       // Modal backgrounds
        'input' => '#0d1117',       // Input/select darker background
        'button' => '#21262d',      // Button background (neutral)
    ],

    // Form elements
    'form' => [
        'bg' => '#151b23',
        'border' => 'white/10',
        'border_focus' => 'emerald-500/50',
        'text' => 'white',
        'placeholder' => 'white/40',
    ],

    // Border colors (global)
    'border' => [
        'default' => 'white/10',
        'light' => 'white/5',
        'medium' => 'white/15',
        'strong' => 'white/25',
    ],

    // Text colors (global)
    'text' => [
        'primary' => 'white',
        'secondary' => 'white/70',
        'tertiary' => 'white/50',
        'disabled' => 'white/30',
        'muted' => 'white/40',
    ],
];
