<?php

namespace S2BR\MobileSplashscreen\Commands;

use Native\Mobile\Plugins\Commands\NativePluginHookCommand;

class CopyAssetsCommand extends NativePluginHookCommand
{
    protected $signature = 'nativephp:mobile-splashscreen:copy-assets';

    protected $description = 'Copy and prepare Lottie animation assets for MobileSplashscreen';

    public function handle(): int
    {
        $paths = $this->resolveAllAnimationPaths();

        if (empty($paths)) {
            $this->warn('No animation paths configured — skipping asset copy.');

            return self::SUCCESS;
        }

        foreach ($paths as $sourcePath) {
            $this->info("Deploying: {$sourcePath}");

            if ($this->isIos()) {
                $this->deployToIos($sourcePath);
            }

            if ($this->isAndroid()) {
                $this->deployToAndroid($sourcePath);
            }
        }

        return self::SUCCESS;
    }

    protected function resolveAllAnimationPaths(): array
    {
        $paths = [];

        // Primary animation
        $primaryPath = $this->resolveAnimationPath(
            config('mobile-splashscreen.animation.path')
        );
        if ($primaryPath) {
            $paths[] = $primaryPath;
        }

        // Dark mode animation
        $darkPath = $this->resolveAnimationPath(
            config('mobile-splashscreen.theme.dark.animation_path')
        );
        if ($darkPath && $darkPath !== $primaryPath) {
            $paths[] = $darkPath;
        }

        // Scheduled animations
        foreach ($this->loadScheduleEntries() as $entry) {
            $scheduledPath = $this->resolveAnimationPath($entry['animation'] ?? null);
            if ($scheduledPath && ! in_array($scheduledPath, $paths)) {
                $paths[] = $scheduledPath;
            }
        }

        return $paths;
    }

    protected function resolveAnimationPath(?string $configured): ?string
    {
        if (! $configured) {
            return null;
        }

        $full = base_path($configured);

        if (file_exists($full)) {
            return $full;
        }

        $this->warn("Animation file not found: {$configured}");

        return null;
    }

    protected function loadScheduleEntries(): array
    {
        $schedulePath = config('mobile-splashscreen.schedule_path');
        if (! $schedulePath) {
            return [];
        }

        $fullPath = base_path($schedulePath);
        if (! file_exists($fullPath)) {
            $this->warn("Schedule file not found: {$schedulePath}");

            return [];
        }

        $data = json_decode(file_get_contents($fullPath), true);

        return $data['schedule'] ?? [];
    }

    protected function deployToIos(string $sourcePath): void
    {
        $animDir = $this->buildPath().'/NativePHP/Resources/animations';
        $this->ensureDirectory($animDir);

        $animationName = pathinfo($sourcePath, PATHINFO_FILENAME);
        $destPath = $animDir.'/'.$animationName.'.lottie';

        if ($this->isV2Format($sourcePath)) {
            $this->info('Detected dotLottie v2 — converting to v1 for lottie-spm...');

            if ($this->convertToV1($sourcePath, $destPath)) {
                $this->info("iOS: v1 animation → {$animationName}.lottie");
            } else {
                $this->error('v2→v1 conversion failed. Copying original (may not play on iOS).');
                copy($sourcePath, $destPath);
            }
        } else {
            copy($sourcePath, $destPath);
            $this->info("iOS: animation → {$animationName}.lottie");
        }
    }

    protected function deployToAndroid(string $sourcePath): void
    {
        $assetsDir = $this->buildPath().'/app/src/main/assets/animations';
        $this->ensureDirectory($assetsDir);

        $filename = pathinfo($sourcePath, PATHINFO_BASENAME);
        $destPath = $assetsDir.'/'.$filename;

        copy($sourcePath, $destPath);
        $this->info("Android: animation → {$filename}");
    }

    /**
     * Returns true if the .lottie file uses dotLottie v2 manifest format.
     * v2 uses manifest version "2" and stores animation at a/<name>.json.
     * lottie-spm on iOS only supports v1 (manifest version "1.0", animations/main.json).
     */
    protected function isV2Format(string $path): bool
    {
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            return false;
        }

        $manifest = $zip->getFromName('manifest.json');
        $zip->close();

        if (! $manifest) {
            return false;
        }

        $data = json_decode($manifest, true);

        return ($data['version'] ?? '1.0') !== '1.0';
    }

    /**
     * Converts a dotLottie v2 archive to v1 format that lottie-spm understands.
     *
     * v1 format requirements:
     *   - manifest.json: {"version":"1.0","animations":[{"id":"main","mode":"normal","direction":1}]}
     *   - animations/main.json: the Lottie animation JSON
     *
     * Also strips features that crash lottie-spm:
     *   - fonts block (crashes when font is missing)
     *   - layer effects (ef)
     *   - hasMask property
     *   - text layers (ty=5, crash when referenced font is not embedded)
     *   - Background layer
     */
    protected function convertToV1(string $sourcePath, string $destPath): bool
    {
        $zip = new \ZipArchive;
        if ($zip->open($sourcePath) !== true) {
            return false;
        }

        $animEntry = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (str_starts_with($name, 'a/') && str_ends_with($name, '.json')) {
                $animEntry = $name;
                break;
            }
        }

        if (! $animEntry) {
            $this->warn('Could not locate animation JSON in v2 archive.');
            $zip->close();

            return false;
        }

        $animJson = $zip->getFromName($animEntry);
        $zip->close();

        if (! $animJson) {
            return false;
        }

        $data = json_decode($animJson, true);

        unset($data['fonts']);
        foreach ($data['layers'] as &$layer) {
            unset($layer['ef'], $layer['hasMask']);
        }
        unset($layer);

        $data['layers'] = array_values(array_filter(
            $data['layers'],
            fn ($l) => ($l['nm'] ?? '') !== 'Background' && ($l['ty'] ?? 0) !== 5
        ));

        $data['nm'] = 'main';

        $manifest = json_encode([
            'version' => '1.0',
            'animations' => [['id' => 'main', 'mode' => 'normal', 'direction' => 1]],
        ]);

        $out = new \ZipArchive;
        if ($out->open($destPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return false;
        }

        $out->addFromString('manifest.json', $manifest);
        $out->addFromString('animations/main.json', json_encode($data));
        $out->close();

        $this->info(sprintf(
            'Conversion summary: %d layers, %d fps, %d frames',
            count($data['layers']),
            $data['fr'] ?? 0,
            $data['op'] ?? 0
        ));

        return true;
    }
}
