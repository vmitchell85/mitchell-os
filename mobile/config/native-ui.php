<?php

/**
 * Native UI — Theme Tokens
 *
 * Published via `php artisan vendor:publish --tag=native-ui-config`.
 * Edit to customize your app's visual identity in one place.
 *
 * For dynamic per-tenant theming, use Native\Mobile\UI\Theme::merge([...])
 * from a service provider. Runtime merges deep-merge on top of these values.
 *
 * Decision log: /docs/NATIVE-UI-REWRITE-PLAN.md (D — theme layer)
 */

return [

    /*
    |---------------------------------------------------------------------------
    | Theme
    |---------------------------------------------------------------------------
    |
    | 17 color tokens, 4 radii, 4 font sizes, font family.
    |
    | "on-X" means "color of content placed ON a surface of color X"
    |   — i.e., text/icons on that background.
    |
    | Color tokens accept:
    |   - CSS hex: '#B91C1C', '#F00', or with alpha '#8B5CF680' (#RRGGBBAA)
    |   - Tailwind palette names: 'red-300', 'orange-800'
    |   - Opacity modifiers on either: 'red-300/20', '#8B5CF6/50'
    |
    | Dark mode is auto-derived from `light` when `dark` is not set. To opt
    | into explicit dark tokens, fill out the `dark` block.
    |
    | The default pairs meet WCAG AA (4.5:1) — if you customize, keep each
    | `on-*` color at 4.5:1 contrast against its background token.
    |
    */

    'theme' => [

        'light' => [
            // Warm terracotta and cream: a calm, household feel.
            'primary' => '#C2410C',
            'on-primary' => '#FFFFFF',

            'secondary' => '#78716C',
            'on-secondary' => '#FFFFFF',

            'surface' => '#FFFFFF',
            'on-surface' => '#292524',
            'background' => '#FBF7F2',
            'on-background' => '#292524',

            'surface-variant' => '#F3ECE3',
            'on-surface-variant' => '#6B635B',

            'outline' => '#E3D9CC',

            'destructive' => '#B91C1C',
            'on-destructive' => '#FFFFFF',

            'accent' => '#A16207',
            'on-accent' => '#FFFFFF',
        ],

        'dark' => [
            'primary' => '#FB923C',
            'on-primary' => '#1C1917',

            'secondary' => '#A8A29E',
            'on-secondary' => '#1C1917',

            'surface' => '#26211D',
            'on-surface' => '#F5EFE8',
            'background' => '#1A1613',
            'on-background' => '#F5EFE8',

            'surface-variant' => '#332C26',
            'on-surface-variant' => '#B8AEA3',

            'outline' => '#4A4037',

            'destructive' => '#F87171',
            'on-destructive' => '#1C1917',

            'accent' => '#FCD34D',
            'on-accent' => '#1C1917',
        ],

        // Corner radii (points / dp).
        'radius-sm' => 4,
        'radius-md' => 12,
        'radius-lg' => 20,
        'radius-full' => 9999,

        // Font size scale (points / sp).
        'font-sm' => 14,
        'font-md' => 16,
        'font-lg' => 20,
        'font-xl' => 24,
    ],

    'fonts' => [
        'default' => 'System',
        'accent' => 'Archivo+Black-Regular',
        'lobster' => 'Lobster+Two-Regular',
    ],

];
