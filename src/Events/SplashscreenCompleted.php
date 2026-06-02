<?php

namespace S2BR\MobileSplashscreen\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SplashscreenCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly float $duration,
        public readonly ?string $animationName = null,
    ) {}
}
