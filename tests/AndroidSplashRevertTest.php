<?php

use S2BR\MobileSplashscreen\Concerns\GeneratesAndroidCode;

/** Exposes the protected revert helper for testing. */
function splashReverter(): object
{
    return new class
    {
        use GeneratesAndroidCode;

        public function revert(string $content, string $template): string
        {
            return $this->revertAndroidMainActivity($content, $template);
        }
    };
}

it('reverts a patched MainActivity back to the NativePHP default splash', function () {
    $patched = <<<'KT'
class MainActivity {
    private var showSplash by mutableStateOf(true)
    private var webViewReadyForSplash by mutableStateOf(false)

    fun onReady() {
            // Signal WebView ready — SplashScreen() will play its transition then hide
            webViewReadyForSplash = true
    }

    AnimatedVisibility(visible = showSplash, exit = fadeOut(animationSpec = tween(0)))

    @Composable
    private fun SplashScreen() {
        Brush.verticalGradient(listOf(Color(0xFF000000)))
        LottieAnimation(composition = composition)
    }
}
KT;

    $template = <<<'KT'
    @Composable
    private fun SplashScreen() {
        Box(modifier = Modifier.fillMaxSize()) { /* NativePHP default */ }
    }
KT;

    expect(splashReverter()->revert($patched, $template))
        ->not->toContain('webViewReadyForSplash')   // patch declaration + assignment removed
        ->not->toContain('Brush.verticalGradient')   // custom composable swapped out
        ->not->toContain('LottieAnimation')
        ->toContain('showSplash = false')            // pristine dismiss restored
        ->toContain('fadeOut(animationSpec = tween(300))')
        ->toContain('NativePHP default');            // default composable swapped in
});

it('is a no-op on an already-default MainActivity', function () {
    $default = <<<'KT'
class MainActivity {
    private var showSplash by mutableStateOf(true)
    @Composable
    private fun SplashScreen() { /* default */ }
}
KT;

    expect(splashReverter()->revert($default, ''))->toBe($default);
});
