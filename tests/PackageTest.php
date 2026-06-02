<?php

it('has a valid package name', function () {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    expect($composer['name'])->toBe('s2br/nativephp-mobile-splashscreen');
});

it('declares nativephp-plugin type', function () {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    expect($composer['type'])->toBe('nativephp-plugin');
});

it('has a valid nativephp.json manifest', function () {
    $manifest = json_decode(file_get_contents(__DIR__.'/../nativephp.json'), true);
    expect($manifest)->toHaveKeys(['namespace', 'events', 'hooks']);
    expect($manifest['namespace'])->toBe('MobileSplashscreen');
});

it('declares both events in the manifest', function () {
    $manifest = json_decode(file_get_contents(__DIR__.'/../nativephp.json'), true);
    expect($manifest['events'])->toContain('S2BR\\MobileSplashscreen\\Events\\SplashscreenCompleted');
    expect($manifest['events'])->toContain('S2BR\\MobileSplashscreen\\Events\\SplashscreenLoopCompleted');
});

it('declares both hooks in the manifest', function () {
    $manifest = json_decode(file_get_contents(__DIR__.'/../nativephp.json'), true);
    expect($manifest['hooks'])->toHaveKey('pre_compile');
    expect($manifest['hooks'])->toHaveKey('copy_assets');
});
