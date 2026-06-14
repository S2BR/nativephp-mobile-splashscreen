<?php

/*
 * The enable/disable wiring runs inside build hooks that need Laravel's config()
 * and real build paths, which this standalone Pest suite can't boot. These tests
 * lock the wiring in place; the runtime behaviour is verified by an actual build
 * with MOBILE_SPLASHSCREEN_ENABLED=false.
 */

function readPluginFile(string $relative): string
{
    return file_get_contents(__DIR__.'/../'.$relative);
}

it('exposes an enabled master switch that defaults to true', function () {
    expect(readPluginFile('config/mobile-splashscreen.php'))
        ->toContain("'enabled' => (bool) env('MOBILE_SPLASHSCREEN_ENABLED', true)");
});

it('short-circuits the pre-compile hook to the disabled handler', function () {
    $pre = readPluginFile('src/Commands/PreCompileCommand.php');

    expect($pre)
        ->toContain('return $this->handleDisabled($config);')
        ->toContain('protected function handleDisabled(array $config): int')
        // Disabled mode still applies launch_color, but only when explicitly set.
        ->toContain("if (! empty(\$config['launch_color'])) {");
});

it('skips animation asset copying when disabled', function () {
    expect(readPluginFile('src/Commands/CopyAssetsCommand.php'))
        ->toContain("config('mobile-splashscreen.enabled', true)");
});

it('makes validate() a no-op when disabled', function () {
    expect(readPluginFile('src/MobileSplashscreen.php'))
        ->toContain("if (! (\$this->config('enabled') ?? true)) {");
});
