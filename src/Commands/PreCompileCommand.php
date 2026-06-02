<?php

namespace S2BR\MobileSplashscreen\Commands;

use Native\Mobile\Plugins\Commands\NativePluginHookCommand;
use S2BR\MobileSplashscreen\Concerns\GeneratesAndroidCode;
use S2BR\MobileSplashscreen\Concerns\GeneratesIosCode;
use S2BR\MobileSplashscreen\MobileSplashscreen;

class PreCompileCommand extends NativePluginHookCommand
{
    use GeneratesAndroidCode, GeneratesIosCode;

    protected $signature = 'nativephp:mobile-splashscreen:pre-compile';

    protected $description = 'Pre-compile hook for MobileSplashscreen plugin';

    public function handle(): int
    {
        $config = config('mobile-splashscreen');

        foreach (app(MobileSplashscreen::class)->validate() as $error) {
            $this->warn($error);
        }

        if ($this->isIos()) {
            $animationName = $this->resolveAnimationName($config);
            $this->patchIosAppState();
            $this->patchIosNativePHPApp();
            $this->modifyIosSplashView($config, $animationName);
            $this->modifyLaunchScreen($config);
        }

        if ($this->isAndroid()) {
            $animationFilename = $this->resolveAnimationFilename($config);
            $this->patchAndroidMainActivity();
            $this->modifyAndroidSplashScreen($config, $animationFilename);
            $this->modifyAndroidTheme($config);
        }

        $this->configureCleanupExclusions();

        return self::SUCCESS;
    }

    /**
     * Resolves the primary animation name (without extension) for iOS.
     * When theme.mode = 'dark', returns the dark animation name if configured.
     */
    protected function resolveAnimationName(array $config): string
    {
        $themeMode = $config['theme']['mode'] ?? 'auto';

        if ($themeMode === 'dark') {
            $darkPath = $config['theme']['dark']['animation_path'] ?? null;
            if ($darkPath) {
                return pathinfo($darkPath, PATHINFO_FILENAME);
            }
        }

        $path = $config['animation']['path'] ?? null;

        return $path ? pathinfo($path, PATHINFO_FILENAME) : 'animation';
    }

    /**
     * Resolves the primary animation filename (with .lottie) for Android.
     * When theme.mode = 'dark', returns the dark animation filename if configured.
     */
    protected function resolveAnimationFilename(array $config): string
    {
        $themeMode = $config['theme']['mode'] ?? 'auto';

        if ($themeMode === 'dark') {
            $darkPath = $config['theme']['dark']['animation_path'] ?? null;
            if ($darkPath) {
                return pathinfo($darkPath, PATHINFO_FILENAME).'.lottie';
            }
        }

        $path = $config['animation']['path'] ?? null;

        return $path ? pathinfo($path, PATHINFO_FILENAME).'.lottie' : 'animation.lottie';
    }

    protected function modifyIosSplashView(array $config, string $animationName): void
    {
        $path = $this->buildPath().'/NativePHP/SplashView.swift';

        if (! file_exists($path)) {
            $this->warn("SplashView.swift not found at: {$path}");

            return;
        }

        $newContent = $this->buildIosSplashView($config, $animationName);

        if (file_put_contents($path, $newContent) === false) {
            $this->error('Failed to write SplashView.swift');

            return;
        }

        $this->info("iOS: SplashView.swift updated (animation: {$animationName})");
    }

    /**
     * Patches AppState.swift to add isSplashDismissed state.
     * SplashView sets this after its custom transition completes, so NativePHP
     * doesn't remove the view until our animation is fully done.
     */
    protected function patchIosAppState(): void
    {
        $path = $this->buildPath().'/NativePHP/AppState.swift';

        if (! file_exists($path)) {
            return;
        }

        $content = file_get_contents($path);

        // Idempotent: skip if already patched
        if (str_contains($content, 'isSplashDismissed')) {
            return;
        }

        $content = str_replace(
            '@Published var isInitialized = false',
            "@Published var isInitialized = false\n\n    /// Splash transition complete — set by SplashView after its custom exit animation\n    @Published var isSplashDismissed = false",
            $content
        );

        file_put_contents($path, $content);
        $this->info('iOS: AppState.swift patched (isSplashDismissed)');
    }

    /**
     * Patches NativePHPApp.swift to use isSplashDismissed instead of isInitialized
     * for the SplashView removal condition, giving our custom transition control over timing.
     */
    protected function patchIosNativePHPApp(): void
    {
        $path = $this->buildPath().'/NativePHP/NativePHPApp.swift';

        if (! file_exists($path)) {
            return;
        }

        $content = file_get_contents($path);

        // Idempotent: skip if already patched
        if (str_contains($content, 'isSplashDismissed')) {
            return;
        }

        $content = str_replace('!appState.isInitialized', '!appState.isSplashDismissed', $content);
        $content = str_replace('value: appState.isInitialized', 'value: appState.isSplashDismissed', $content);

        file_put_contents($path, $content);
        $this->info('iOS: NativePHPApp.swift patched (isSplashDismissed)');
    }

    /**
     * Patches MainActivity.kt so the splash dismissal is controlled by our SplashScreen()
     * composable (after its custom transition) instead of immediately after loadUrl().
     */
    protected function patchAndroidMainActivity(): void
    {
        $path = $this->buildPath().'/app/src/main/java/com/nativephp/mobile/ui/MainActivity.kt';

        if (! file_exists($path)) {
            return;
        }

        $content = file_get_contents($path);

        // Idempotent: skip if already patched
        if (str_contains($content, 'webViewReadyForSplash')) {
            return;
        }

        // Add webViewReadyForSplash state right after showSplash
        $content = str_replace(
            'private var showSplash by mutableStateOf(true)',
            "private var showSplash by mutableStateOf(true)\n    private var webViewReadyForSplash by mutableStateOf(false)",
            $content
        );

        // Signal WebView ready instead of immediately hiding splash
        $content = str_replace(
            "// Hide splash screen after URL is loaded\n            showSplash = false",
            "// Signal WebView ready — SplashScreen() will play its transition then hide\n            webViewReadyForSplash = true",
            $content
        );

        // Make AnimatedVisibility removal instant (our transition already animated content away)
        $content = str_replace(
            'exit = fadeOut(animationSpec = tween(300))',
            'exit = fadeOut(animationSpec = tween(0))',
            $content
        );

        file_put_contents($path, $content);
        $this->info('Android: MainActivity.kt patched (webViewReadyForSplash)');
    }

    protected function modifyLaunchScreen(array $config): void
    {
        $path = $this->buildPath().'/NativePHP/LaunchScreen.storyboard';

        if (! file_exists($path)) {
            return;
        }

        $hex = $this->resolveFirstBackgroundColor($config);
        $storyboardColor = $this->hexToStoryboardColor($hex);

        $originalPath = $path.'.original';
        if (! file_exists($originalPath)) {
            copy($path, $originalPath);
        }

        $content = file_get_contents($originalPath);
        $content = preg_replace('/<imageView.*?<\/imageView>/s', '', $content);
        $content = preg_replace('/<constraints>.*?<\/constraints>/s', '', $content);
        $content = preg_replace('/\s*<resources>.*?<\/resources>/s', '', $content);
        $content = preg_replace(
            '/(<view[^>]*>)\s*(<rect[^>]*\/>)/s',
            "$1\n                $2\n                <color key=\"backgroundColor\" {$storyboardColor}/>",
            $content
        );

        file_put_contents($path, $content);
        $this->info('iOS: LaunchScreen.storyboard updated');
    }

    protected function modifyAndroidSplashScreen(array $config, string $animationFilename): void
    {
        $path = $this->buildPath().'/app/src/main/java/com/nativephp/mobile/ui/MainActivity.kt';

        if (! file_exists($path)) {
            $this->warn("MainActivity.kt not found at: {$path}");

            return;
        }

        $backupDir = $this->buildPath().'/app/src/main/nativephp-backups';
        $this->ensureDirectory($backupDir);
        $originalPath = $backupDir.'/MainActivity.kt.original';

        if (! file_exists($originalPath)) {
            copy($path, $originalPath);
        }

        $content = file_get_contents($originalPath);

        foreach ($this->buildAndroidImports($config) as $import) {
            if (! str_contains($content, $import)) {
                $content = preg_replace(
                    '/(import androidx\.compose\.runtime\.\*)/',
                    "$1\n{$import}",
                    $content
                );
            }
        }

        $newComposable = $this->buildAndroidSplashScreen($config, $animationFilename);
        $content = preg_replace(
            '/@Composable\s+private fun SplashScreen\(\).*?^    \}/ms',
            $newComposable,
            $content,
            1
        );

        file_put_contents($path, $content);
        $this->info("Android: MainActivity.kt updated (animation: {$animationFilename})");
    }

    protected function modifyAndroidTheme(array $config): void
    {
        $hex = $this->resolveFirstBackgroundColor($config);
        $androidColor = $this->hexToAndroidColor($hex);

        $themeFiles = [
            'values/themes.xml' => 'themes.xml.original',
            'values-v31/themes.xml' => 'themes-v31.xml.original',
        ];

        $backupDir = $this->buildPath().'/app/src/main/nativephp-backups';
        $this->ensureDirectory($backupDir);

        foreach ($themeFiles as $relativePath => $backupName) {
            $themesPath = $this->buildPath().'/app/src/main/res/'.$relativePath;
            if (! file_exists($themesPath)) {
                continue;
            }

            $originalPath = $backupDir.'/'.$backupName;
            if (! file_exists($originalPath)) {
                copy($themesPath, $originalPath);
            }

            $content = file_get_contents($originalPath);
            $splashAttributes = <<<XML
            <item name="android:windowBackground">{$androidColor}</item>
            <item name="android:windowSplashScreenBackground">{$androidColor}</item>
            <item name="android:windowSplashScreenAnimatedIcon">@android:color/transparent</item>
            <item name="android:windowSplashScreenIconBackgroundColor">{$androidColor}</item>
        </style>
XML;
            $content = preg_replace('/<\/style>\s*<\/resources>/s', $splashAttributes."\n</resources>", $content);
            file_put_contents($themesPath, $content);
        }

        $this->info('Android: theme colors updated');
    }

    /**
     * Resolves the first (primary) background color for native system theme integration.
     * Respects theme.mode=dark override.
     */
    protected function resolveFirstBackgroundColor(array $config): string
    {
        if (! empty($config['launch_color'])) {
            return ltrim($config['launch_color'], '#');
        }

        $themeMode = $config['theme']['mode'] ?? 'auto';

        if ($themeMode === 'dark') {
            $darkBg = $config['theme']['dark']['background'] ?? [];
            if (($darkBg['type'] ?? null) !== null) {
                return $this->extractFirstColor($darkBg);
            }
        }

        return $this->extractFirstColor($config['background']);
    }

    protected function extractFirstColor(array $bg): string
    {
        if (($bg['type'] ?? 'color') === 'gradient') {
            return $bg['gradient']['colors'][0] ?? '#FFFFFF';
        }

        return $bg['color'] ?? '#FFFFFF';
    }

    protected function configureCleanupExclusions(): void
    {
        $current = config('nativephp.cleanup_exclude_files', []);
        config([
            'nativephp.cleanup_exclude_files' => array_unique(
                array_merge($current, ['*.lottie', 'animations/*.lottie'])
            ),
        ]);
    }
}
