<?php

namespace S2BR\MobileSplashscreen;

use Illuminate\Support\Facades\Http;

class MobileSplashscreen
{
    protected string $storageDir = 'app/splashscreen';

    public function config(?string $key = null): mixed
    {
        $base = 'mobile-splashscreen';

        return config($key ? "{$base}.{$key}" : $base);
    }

    public function validate(): array
    {
        $errors = [];

        $content = $this->config('content');
        if (! in_array($content, ['animation', 'text'])) {
            $errors[] = "Invalid content type '{$content}'. Must be 'animation' or 'text'.";
        }

        if ($content === 'animation') {
            $path = $this->config('animation.path');
            if ($path && ! file_exists(base_path($path))) {
                $errors[] = "Animation file not found: {$path}";
            }
        }

        $bgType = $this->config('background.type');
        if (! in_array($bgType, ['color', 'gradient'])) {
            $errors[] = "Invalid background type '{$bgType}'. Must be 'color' or 'gradient'.";
        }

        if ($bgType === 'gradient') {
            $colors = $this->config('background.gradient.colors');
            if (count($colors) < 2) {
                $errors[] = 'Gradient requires at least 2 colors.';
            }
        }

        if ($content === 'animation') {
            $position = $this->config('animation.position');
            if (! in_array($position, ['center', 'top', 'bottom'])) {
                $errors[] = "Invalid position '{$position}'. Must be 'center', 'top', or 'bottom'.";
            }
        }

        $progressBarDirection = $this->config('progress_bar.direction');
        if (! in_array($progressBarDirection, ['ltr', 'rtl'])) {
            $errors[] = "Invalid progress bar direction '{$progressBarDirection}'. Must be 'ltr' or 'rtl'.";
        }

        return $errors;
    }

    /**
     * Download animations.json from a remote URL and store it locally.
     * Then resolve the active entry for today's date and write active-splash.json.
     */
    public function syncFromUrl(string $url): static
    {
        $dir = storage_path($this->storageDir);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $response = Http::timeout(30)->get($url);
        $response->throw();

        $json = $response->json();
        file_put_contents($dir.'/animations.json', json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $this;
    }

    /**
     * Read the locally stored animations.json, pre-download animation files for all
     * entries that are currently active or start within $daysAhead days, and write
     * schedule-local.json with normalised entries (local file paths, flat date fields)
     * for on-device date resolution at app launch.
     *
     * Supports both flat date fields ({ "from": "...", "to": "..." }) and the
     * nested format used by the S2BR portal API ({ "date": { "from": "...", "to": "..." } }).
     *
     * Returns the number of entries written to schedule-local.json.
     */
    public function resolveActive(int $daysAhead = 30): int
    {
        $dir = storage_path($this->storageDir);
        $jsonPath = $dir.'/animations.json';

        if (! file_exists($jsonPath)) {
            return 0;
        }

        $data = json_decode(file_get_contents($jsonPath), true);
        $schedule = $data['schedule'] ?? [];

        $todayStr = now()->format('Y-m-d');
        $cutoff = now()->addDays($daysAhead)->format('Y-m-d');
        $animDir = $dir.'/animations';
        $normalized = [];

        foreach ($schedule as $entry) {
            $from = $entry['date']['from'] ?? $entry['from'] ?? '';
            $to = $entry['date']['to'] ?? $entry['to'] ?? '';

            if ($from === '' || $to === '') {
                continue;
            }

            $isActive = $this->isDateInRange($todayStr, $from, $to);
            $startsWithinWindow = $this->startsWithinWindow($todayStr, $from, $cutoff);

            if (! $isActive && ! $startsWithinWindow) {
                continue;
            }

            $animUrl = $entry['url'] ?? $entry['animation_url'] ?? null;

            $row = array_filter([
                'name' => $entry['name'] ?? null,
                'from' => $from,
                'to' => $to,
                'background' => $entry['background'] ?? null,
            ]);

            // Pass through all per-entry overrides that native code resolves at runtime
            $passThrough = [
                'loop', 'size', 'position',
                'transition_out', 'transition_duration', 'transition_origin',
                'delay_before', 'fade_in', 'delay_after',
                'on_complete', 'on_loop',
                'show_icon', 'icon_size', 'icon_position', 'icon_radius',
                'progress_bar', 'progress_bar_color', 'progress_bar_direction',
            ];
            foreach ($passThrough as $field) {
                if (isset($entry[$field])) {
                    $row[$field] = $entry[$field];
                }
            }

            if ($animUrl) {
                if (! is_dir($animDir)) {
                    mkdir($animDir, 0755, true);
                }

                $filename = $entry['animation'] ?? pathinfo($animUrl, PATHINFO_BASENAME);
                $dest = $animDir.'/'.$filename;

                if (! file_exists($dest)) {
                    $response = Http::timeout(60)->get($animUrl);
                    $response->throw();
                    file_put_contents($dest, $response->body());
                }

                $row['animation'] = 'splashscreen/animations/'.$filename;
            }

            $normalized[] = $row;
        }

        if (empty($normalized)) {
            $this->clearActive();
            $this->pruneAnimationFiles([]);

            return 0;
        }

        file_put_contents(
            $dir.'/schedule-local.json',
            json_encode([
                'updated_at' => $todayStr,
                'schedule' => $normalized,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        $this->pruneAnimationFiles(array_column($normalized, 'animation'));

        return count($normalized);
    }

    /**
     * Delete animation files from storage that are no longer referenced by any
     * upcoming schedule entry. Pass an empty array to remove all downloaded files.
     *
     * @param  array<string>  $referencedPaths  Relative paths like "splashscreen/animations/foo.lottie"
     */
    protected function pruneAnimationFiles(array $referencedPaths): void
    {
        $animDir = storage_path($this->storageDir.'/animations');

        if (! is_dir($animDir)) {
            return;
        }

        $keepFilenames = array_map('basename', array_filter($referencedPaths));

        foreach (new \DirectoryIterator($animDir) as $file) {
            if ($file->isDot() || $file->isDir()) {
                continue;
            }

            if (! in_array($file->getFilename(), $keepFilenames, true)) {
                unlink($file->getPathname());
            }
        }
    }

    public function clearActive(): void
    {
        $path = storage_path($this->storageDir.'/schedule-local.json');
        if (file_exists($path)) {
            unlink($path);
        }
    }

    public function hasActive(): bool
    {
        return file_exists(storage_path($this->storageDir.'/schedule-local.json'));
    }

    /**
     * Date range check. Dates are YYYY-MM-DD. Handles year-boundary ranges.
     */
    protected function isDateInRange(string $today, string $from, string $to): bool
    {
        if ($from === '' || $to === '') {
            return false;
        }

        // Full YYYY-MM-DD dates match only their specific year; partial MM-DD dates
        // recur every year, so they are compared against today's month-day only.
        $isFull = strlen($from) === 10 && strlen($to) === 10;
        $value = $isFull ? $today : substr($today, 5);

        if ($from <= $to) {
            return $value >= $from && $value <= $to;
        }

        // Spans the year boundary (e.g. 12-31 → 01-02).
        return $value >= $from || $value <= $to;
    }

    /**
     * Whether an entry's start falls within the pre-download window
     * (after today, on or before the cutoff).
     *
     * Full YYYY-MM-DD dates are compared directly. Partial MM-DD dates resolve to
     * their next yearly occurrence so recurring seasons are fetched ahead of time.
     */
    protected function startsWithinWindow(string $today, string $from, string $cutoff): bool
    {
        if ($from === '') {
            return false;
        }

        if (strlen($from) === 10) {
            return $from > $today && $from <= $cutoff;
        }

        $year = (int) substr($today, 0, 4);
        $next = $year.'-'.$from;
        if ($next < $today) {
            $next = ($year + 1).'-'.$from;
        }

        return $next > $today && $next <= $cutoff;
    }
}
