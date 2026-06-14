<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Master switch for the custom splash screen. When false, this plugin
    | injects nothing and the app falls back to NativePHP's default splash
    | handling — no animation, background, or progress bar from this package.
    |
    | The OS still shows its native launch screen briefly at cold start (that
    | cannot be removed); if 'launch_color' is set it is still applied so that
    | screen can match your brand even when the custom splash is disabled.
    |
    */

    'enabled' => (bool) env('MOBILE_SPLASHSCREEN_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Content Type
    |--------------------------------------------------------------------------
    |
    | Determines what to display as the primary splash content.
    |
    | Options: 'animation' | 'text'
    |
    */

    'content' => env('MOBILE_SPLASHSCREEN_CONTENT', 'animation'),

    /*
    |--------------------------------------------------------------------------
    | Animation
    |--------------------------------------------------------------------------
    |
    | path       - Relative path from project root to the .lottie file.
    |              Supports both dotLottie v1 and v2 — v2 is auto-converted
    |              to v1 at build time so it works with lottie-spm on iOS.
    |
    | loop       - true loops indefinitely; false plays once then holds on
    |              the last frame until the app is ready.
    |
    | size       - Width as a fraction of screen width (0.1 to 1.0).
    |
    | position   - Vertical alignment: 'center' | 'top' | 'bottom'
    |
    */

    'animation' => [
        'path' => env('MOBILE_SPLASHSCREEN_ANIMATION_PATH', null),
        'loop' => (bool) env('MOBILE_SPLASHSCREEN_LOOP', true),
        'size' => (float) env('MOBILE_SPLASHSCREEN_SIZE', 0.8),
        'position' => env('MOBILE_SPLASHSCREEN_POSITION', 'center'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Text
    |--------------------------------------------------------------------------
    |
    | Configuration for text-only splash screens (content = 'text').
    |
    | weight - thin | light | regular | medium | semibold | bold | heavy | black
    |
    */

    'text' => [
        'message' => env('MOBILE_SPLASHSCREEN_TEXT', ''),
        'color' => env('MOBILE_SPLASHSCREEN_TEXT_COLOR', '#FFFFFF'),
        'size' => (int) env('MOBILE_SPLASHSCREEN_TEXT_SIZE', 32),
        'weight' => env('MOBILE_SPLASHSCREEN_TEXT_WEIGHT', 'bold'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Background
    |--------------------------------------------------------------------------
    |
    | type       - 'color' for a solid color, 'gradient' for a linear gradient.
    |
    | color      - Hex color used when type = 'color'.
    |
    | gradient   - Used when type = 'gradient'.
    |   colors   - Comma-separated hex values, min 2. From ENV:
    |              MOBILE_SPLASHSCREEN_GRADIENT_COLORS="#079F3D,#046B28"
    |   direction - 'vertical' | 'horizontal' | 'diagonal'
    |
    | launch_color - Solid color shown by the OS before the app launches and
    |                the animation begins. Baked into LaunchScreen.storyboard
    |                at compile time. Useful when using dynamic/scheduled
    |                animations whose background varies at runtime. When null,
    |                defaults to the first background/gradient color.
    |
    */

    'launch_color' => env('MOBILE_SPLASHSCREEN_LAUNCH_COLOR', null),

    'background' => [
        'type' => env('MOBILE_SPLASHSCREEN_BG_TYPE', 'color'),
        'color' => env('MOBILE_SPLASHSCREEN_BG_COLOR', '#FFFFFF'),
        'gradient' => [
            'colors' => array_map(
                'trim',
                explode(',', env('MOBILE_SPLASHSCREEN_GRADIENT_COLORS', '#079F3D,#046B28'))
            ),
            'direction' => env('MOBILE_SPLASHSCREEN_GRADIENT_DIRECTION', 'vertical'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | App Icon Overlay
    |--------------------------------------------------------------------------
    |
    | Displays the app icon alongside the animation or text.
    |
    | show          - Whether to show the icon at all.
    | size          - Width as a fraction of screen width (0.1 to 0.5).
    | position      - 'top' or 'bottom' relative to the main content.
    | corner_radius - Fraction of icon width to use as corner radius (0.0–0.5).
    |                 0.5 = fully circular.
    |
    */

    'icon' => [
        'show' => (bool) env('MOBILE_SPLASHSCREEN_SHOW_ICON', false),
        'size' => (float) env('MOBILE_SPLASHSCREEN_ICON_SIZE', 0.2),
        'position' => env('MOBILE_SPLASHSCREEN_ICON_POSITION', 'bottom'),
        'corner_radius' => (float) env('MOBILE_SPLASHSCREEN_ICON_RADIUS', 0.22),
    ],

    /*
    |--------------------------------------------------------------------------
    | Timing
    |--------------------------------------------------------------------------
    |
    | All values are in milliseconds.
    |
    | delay_before - Wait before the splash content fades in.
    | fade_in      - Duration of the initial fade-in.
    | delay_after  - Extra hold after animation ends (single-run only).
    |
    */

    'timing' => [
        'delay_before' => (int) env('MOBILE_SPLASHSCREEN_DELAY_BEFORE', 0),
        'fade_in' => (int) env('MOBILE_SPLASHSCREEN_FADE_IN', 600),
        'delay_after' => (int) env('MOBILE_SPLASHSCREEN_DELAY_AFTER', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Events
    |--------------------------------------------------------------------------
    |
    | on_complete  - Dispatches SplashscreenCompleted when a single-run
    |               animation finishes.
    |
    | on_loop      - Dispatches SplashscreenLoopCompleted after each loop
    |               iteration (loop = true only).
    |
    */

    'events' => [
        'on_complete' => (bool) env('MOBILE_SPLASHSCREEN_EVENT_COMPLETE', true),
        'on_loop' => (bool) env('MOBILE_SPLASHSCREEN_EVENT_LOOP', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Transition Out
    |--------------------------------------------------------------------------
    |
    | The exit animation played within the SplashView before dispatching the
    | SplashscreenCompleted event (single-run animations only).
    |
    | type     - none | fade | scale_up | scale_down | slide_up | slide_down
    |            | circle_expand
    |
    | duration - Duration of the exit animation in milliseconds.
    |
    | origin   - Origin point for circle_expand (expands a transparent hole):
    |            center | top | bottom | top_left | top_right
    |            | bottom_left | bottom_right | center_left | center_right
    |
    */

    'transition_out' => [
        'type' => env('MOBILE_SPLASHSCREEN_TRANSITION_OUT', 'none'),
        'duration' => (int) env('MOBILE_SPLASHSCREEN_TRANSITION_DURATION', 400),
        'origin' => env('MOBILE_SPLASHSCREEN_TRANSITION_ORIGIN', 'center'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Progress Bar
    |--------------------------------------------------------------------------
    |
    | Displays a subtle loading indicator after a single-run animation completes
    | while the app is still loading. Hidden automatically once the app is ready.
    |
    | Only relevant when loop = false (single-run animations).
    |
    | enabled   - true shows the bar; false (default) hides it entirely.
    |
    | direction - Fill direction of the bar.
    |             'ltr' (default) fills from left to right.
    |             'rtl' fills from right to left.
    |
    */

    'progress_bar' => [
        'enabled' => (bool) env('MOBILE_SPLASHSCREEN_PROGRESS_BAR', false),
        'color' => env('MOBILE_SPLASHSCREEN_PROGRESS_BAR_COLOR', '#FFFFFF'),
        'direction' => env('MOBILE_SPLASHSCREEN_PROGRESS_BAR_DIRECTION', 'ltr'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Theme
    |--------------------------------------------------------------------------
    |
    | Controls how the splash screen responds to the system dark/light mode.
    |
    | mode     - 'auto'  follows the system theme at runtime.
    |            'light' always uses the default configuration.
    |            'dark'  always uses the dark overrides below.
    |
    | dark.animation_path  - Alternative .lottie file for dark mode.
    |                        Relative path from project root.
    |
    | dark.background      - Alternative background for dark mode.
    |                        Same structure as the top-level 'background'.
    |                        Set MOBILE_SPLASHSCREEN_DARK_BG_TYPE to activate.
    |
    */

    'theme' => [
        'mode' => env('MOBILE_SPLASHSCREEN_THEME', 'auto'),
        'dark' => [
            'animation_path' => env('MOBILE_SPLASHSCREEN_DARK_ANIMATION_PATH', null),
            'background' => [
                'type' => env('MOBILE_SPLASHSCREEN_DARK_BG_TYPE', null),
                'color' => env('MOBILE_SPLASHSCREEN_DARK_BG_COLOR', '#000000'),
                'gradient' => [
                    'colors' => array_map(
                        'trim',
                        explode(',', env('MOBILE_SPLASHSCREEN_DARK_GRADIENT_COLORS', '#000000,#1A1A1A'))
                    ),
                    'direction' => env('MOBILE_SPLASHSCREEN_DARK_GRADIENT_DIRECTION', 'vertical'),
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Schedule
    |--------------------------------------------------------------------------
    |
    | Path (relative to project root) to a JSON file that defines date-based
    | animation overrides. Useful for seasonal themes, holidays, etc.
    |
    | The schedule is evaluated at runtime on the device — no rebuild needed.
    | All referenced animation files are automatically deployed at build time.
    |
    | Schedule JSON format:
    |
    |   {
    |     "schedule": [
    |       {
    |         "name": "christmas",
    |         "from": "12-24",
    |         "to":   "12-26",
    |         "animation": "resources/animations/christmas.lottie",
    |         "background": {
    |           "type": "gradient",
    |           "colors": ["#1B4F72", "#154360"],
    |           "direction": "vertical"
    |         }
    |       }
    |     ]
    |   }
    |
    | Date format — two styles, mix freely:
    |   - "MM-DD"      (e.g. "12-24"): recurs every year. Use for seasons.
    |   - "YYYY-MM-DD" (e.g. "2026-12-24"): matches only that specific year.
    | Ranges spanning the year boundary (e.g. 12-31→01-02) are handled correctly.
    | When a full-date entry and a recurring entry both match today, the full date
    | wins — so you can override a season for one particular year.
    | The 'background' key is optional per entry.
    |
    | Priority at runtime: schedule > dark-mode override > default.
    |
    */

    'schedule_path' => env('MOBILE_SPLASHSCREEN_SCHEDULE', null),

];
