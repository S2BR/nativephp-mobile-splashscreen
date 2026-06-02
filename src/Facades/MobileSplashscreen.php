<?php

namespace S2BR\MobileSplashscreen\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static mixed config(?string $key = null)
 * @method static array validate()
 * @method static static syncFromUrl(string $url)
 * @method static int resolveActive(int $daysAhead = 30)
 * @method static void clearActive()
 * @method static bool hasActive()
 *
 * @see \S2BR\MobileSplashscreen\MobileSplashscreen
 */
class MobileSplashscreen extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \S2BR\MobileSplashscreen\MobileSplashscreen::class;
    }
}
