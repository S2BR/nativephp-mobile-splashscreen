<?php

namespace S2BR\MobileSplashscreen;

use Illuminate\Support\ServiceProvider;
use S2BR\MobileSplashscreen\Commands\CopyAssetsCommand;
use S2BR\MobileSplashscreen\Commands\PreCompileCommand;
use S2BR\MobileSplashscreen\Commands\SyncAnimationsCommand;

class MobileSplashscreenServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/mobile-splashscreen.php',
            'mobile-splashscreen'
        );

        $this->app->singleton(MobileSplashscreen::class, fn () => new MobileSplashscreen);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                PreCompileCommand::class,
                CopyAssetsCommand::class,
                SyncAnimationsCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/mobile-splashscreen.php' => config_path('mobile-splashscreen.php'),
            ], 'mobile-splashscreen-config');
        }
    }
}
