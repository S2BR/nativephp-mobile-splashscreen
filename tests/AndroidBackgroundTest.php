<?php

use S2BR\MobileSplashscreen\Concerns\GeneratesAndroidCode;

/** Generate the Android splash Kotlin for a given background config. */
function androidWithBackground(array $background): string
{
    $gen = new class
    {
        use GeneratesAndroidCode;

        public function build(array $config): string
        {
            return $this->buildAndroidSplashScreen($config, 'anim.lottie');
        }
    };

    return $gen->build([
        'content' => 'animation',
        'animation' => ['loop' => true, 'size' => 0.8, 'position' => 'center'],
        'background' => $background,
        'progress_bar' => ['enabled' => false, 'color' => '#FFFFFF', 'direction' => 'ltr'],
        'theme' => ['mode' => 'light'],
        'transition_out' => ['type' => 'none', 'duration' => 400],
        'timing' => ['fade_in' => 600],
        'events' => [],
        'icon' => ['show' => false],
    ]);
}

it('wraps a solid-color background in SolidColor so the background block stays a Brush', function () {
    // The background run{} block returns a Brush in its dynamic branches
    // (gradient / SolidColor). A bare Color fallback would mix Brush and Color,
    // making Modifier.background() match no overload and fail to compile.
    $kotlin = androidWithBackground(['type' => 'color', 'color' => '#FF0000']);

    expect($kotlin)->toContain('SolidColor(Color(0xFFFF0000))');
    // No bare Color(...) sitting as the run{} fallback line.
    expect($kotlin)->not->toMatch('/\n\s+Color\(0xFFFF0000\)\n\s+\}\n\s+\)/');
});

it('keeps gradient backgrounds as a Brush', function () {
    $kotlin = androidWithBackground([
        'type' => 'gradient',
        'gradient' => ['colors' => ['#FF0000', '#00FF00'], 'direction' => 'vertical'],
    ]);

    expect($kotlin)->toContain('Brush.verticalGradient');
});
