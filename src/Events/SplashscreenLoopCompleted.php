<?php

namespace S2BR\MobileSplashscreen\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SplashscreenLoopCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $iteration,
        public readonly ?string $animationName = null,
    ) {}
}
