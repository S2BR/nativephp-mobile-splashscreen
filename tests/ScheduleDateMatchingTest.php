<?php

use S2BR\MobileSplashscreen\Concerns\GeneratesAndroidCode;
use S2BR\MobileSplashscreen\Concerns\GeneratesIosCode;
use S2BR\MobileSplashscreen\MobileSplashscreen;

/** Exposes the protected date helpers for direct assertion. */
function dateMatcher(): object
{
    return new class extends MobileSplashscreen
    {
        public function inRange(string $today, string $from, string $to): bool
        {
            return $this->isDateInRange($today, $from, $to);
        }

        public function inWindow(string $today, string $from, string $cutoff): bool
        {
            return $this->startsWithinWindow($today, $from, $cutoff);
        }
    };
}

function scheduleGenerator(): object
{
    return new class
    {
        use GeneratesAndroidCode;
        use GeneratesIosCode;

        public function ios(): string
        {
            return $this->buildIosSplashView(scheduleBaseConfig(), 'splash');
        }

        public function android(): string
        {
            return $this->buildAndroidSplashScreen(scheduleBaseConfig(), 'splash');
        }
    };
}

function scheduleBaseConfig(): array
{
    return [
        'content' => 'animation',
        'animation' => ['path' => 'resources/animations/splash.lottie', 'loop' => false, 'size' => 0.8, 'position' => 'center'],
        'background' => ['type' => 'color', 'color' => '#FFFFFF', 'gradient' => ['colors' => ['#000', '#111'], 'direction' => 'vertical']],
        'icon' => ['show' => false, 'size' => 0.2, 'position' => 'bottom', 'corner_radius' => 0.22],
        'timing' => ['delay_before' => 0, 'fade_in' => 600, 'delay_after' => 0],
        'events' => ['on_complete' => true, 'on_loop' => false],
        'transition_out' => ['type' => 'none', 'duration' => 400, 'origin' => 'center'],
        'progress_bar' => ['enabled' => false, 'color' => '#FFFFFF', 'direction' => 'ltr'],
        'theme' => ['mode' => 'light', 'dark' => ['animation_path' => null, 'background' => ['type' => null]]],
        'schedule_path' => null,
    ];
}

// ── PHP date resolution ──────────────────────────────────────────────────────

it('matches a full YYYY-MM-DD range only within its own year', function () {
    $m = dateMatcher();

    expect($m->inRange('2024-12-25', '2024-12-24', '2024-12-26'))->toBeTrue();
    expect($m->inRange('2025-12-25', '2024-12-24', '2024-12-26'))->toBeFalse();
});

it('matches a partial MM-DD range every year', function () {
    $m = dateMatcher();

    expect($m->inRange('2026-12-25', '12-24', '12-26'))->toBeTrue();
    expect($m->inRange('2030-12-25', '12-24', '12-26'))->toBeTrue();
    expect($m->inRange('2026-06-10', '12-24', '12-26'))->toBeFalse();
});

it('handles a partial range that spans the year boundary', function () {
    $m = dateMatcher();

    expect($m->inRange('2026-12-31', '12-31', '01-02'))->toBeTrue();
    expect($m->inRange('2027-01-01', '12-31', '01-02'))->toBeTrue();
    expect($m->inRange('2026-07-15', '12-31', '01-02'))->toBeFalse();
});

it('detects upcoming full dates within the pre-download window', function () {
    $m = dateMatcher();

    expect($m->inWindow('2026-06-10', '2026-06-20', '2026-07-10'))->toBeTrue();
    expect($m->inWindow('2026-06-10', '2026-08-20', '2026-07-10'))->toBeFalse();
    expect($m->inWindow('2026-06-10', '2026-01-01', '2026-07-10'))->toBeFalse();
});

it('resolves the next yearly occurrence for partial dates in the window', function () {
    $m = dateMatcher();

    // Christmas season starts within the December window.
    expect($m->inWindow('2026-12-01', '12-24', '2026-12-31'))->toBeTrue();
    // Already-passed this year → rolls to next year, still inside the window.
    expect($m->inWindow('2026-12-30', '01-02', '2027-01-15'))->toBeTrue();
    // Next occurrence is past the cutoff.
    expect($m->inWindow('2026-06-10', '12-24', '2026-07-10'))->toBeFalse();
});

// ── Generated native matcher ─────────────────────────────────────────────────

it('emits the full/partial precedence matcher in the generated iOS code', function () {
    $swift = scheduleGenerator()->ios();

    expect($swift)->toContain('let todayMD = String(today.suffix(5))')
        ->toContain('let isFull = from.count == 10 && to.count == 10')
        ->toContain('if isFull { fullMatch = entry; break }')
        ->toContain('if partialMatch == nil { partialMatch = entry }')
        ->toContain('return fullMatch ?? partialMatch');
});

it('emits the full/partial precedence matcher in the generated Android code', function () {
    $kotlin = scheduleGenerator()->android();

    expect($kotlin)->toContain('val todayMD = today.substring(5)')
        ->toContain('val isFull = from.length == 10 && to.length == 10')
        ->toContain('if (isFull) { fullMatch = map; break }')
        ->toContain('if (partialMatch == null) partialMatch = map')
        ->toContain('fullMatch ?: partialMatch');
});
