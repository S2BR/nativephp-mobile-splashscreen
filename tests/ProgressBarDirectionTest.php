<?php

use S2BR\MobileSplashscreen\Concerns\GeneratesAndroidCode;
use S2BR\MobileSplashscreen\Concerns\GeneratesIosCode;

/**
 * Exposes the protected generator traits so their output can be asserted
 * without bootstrapping the full Laravel container. No schedule_path is set
 * in these configs, so the generators never call base_path().
 */
function progressBarGenerator(): object
{
    return new class
    {
        use GeneratesAndroidCode;
        use GeneratesIosCode;

        public function ios(array $config): string
        {
            return $this->buildIosSplashView($config, 'splash');
        }

        public function android(array $config): string
        {
            return $this->buildAndroidSplashScreen($config, 'splash');
        }
    };
}

function progressBarConfig(string $direction): array
{
    return [
        'content' => 'animation',
        'animation' => ['path' => 'resources/animations/splash.lottie', 'loop' => false, 'size' => 0.8, 'position' => 'center'],
        'background' => ['type' => 'color', 'color' => '#FFFFFF', 'gradient' => ['colors' => ['#000', '#111'], 'direction' => 'vertical']],
        'icon' => ['show' => false, 'size' => 0.2, 'position' => 'bottom', 'corner_radius' => 0.22],
        'timing' => ['delay_before' => 0, 'fade_in' => 600, 'delay_after' => 0],
        'events' => ['on_complete' => true, 'on_loop' => false],
        'transition_out' => ['type' => 'none', 'duration' => 400, 'origin' => 'center'],
        'progress_bar' => ['enabled' => true, 'color' => '#FFFFFF', 'direction' => $direction],
        'theme' => ['mode' => 'light', 'dark' => ['animation_path' => null, 'background' => ['type' => null]]],
        'schedule_path' => null,
    ];
}

it('defaults the iOS progress bar to a left-to-right (leading) fill', function () {
    $swift = progressBarGenerator()->ios(progressBarConfig('ltr'));

    expect($swift)->toContain('resolvedProgressBarDirection')
        ->toContain('resolvedProgressBarDirection == "rtl" ? .trailing : .leading')
        ->toContain('ZStack(alignment: resolvedProgressBarAlignment)')
        ->toContain('?? "ltr"');
});

it('bakes the rtl default into the iOS progress bar', function () {
    $swift = progressBarGenerator()->ios(progressBarConfig('rtl'));

    expect($swift)->toContain('?? "rtl"');
});

it('resolves the iOS progress bar direction from dynamic and static schedule entries', function () {
    $swift = progressBarGenerator()->ios(progressBarConfig('ltr'));

    expect($swift)->toContain('Self.dynamic?["progress_bar_direction"] as? String');
});

it('defaults the Android progress bar to a start-aligned (ltr) fill', function () {
    $kotlin = progressBarGenerator()->android(progressBarConfig('ltr'));

    expect($kotlin)->toContain('resolvedProgressBarDirection')
        ->toContain('if (resolvedProgressBarDirection == "rtl") Alignment.CenterEnd else Alignment.CenterStart')
        ->toContain('contentAlignment = resolvedProgressBarAlignment')
        ->toContain('?: "ltr"');
});

it('bakes the rtl default into the Android progress bar', function () {
    $kotlin = progressBarGenerator()->android(progressBarConfig('rtl'));

    expect($kotlin)->toContain('?: "rtl"');
});
