<?php

namespace S2BR\MobileSplashscreen\Commands;

use Illuminate\Console\Command;
use S2BR\MobileSplashscreen\MobileSplashscreen;

class SyncAnimationsCommand extends Command
{
    protected $signature = 'nativephp:mobile-splashscreen:sync
                            {--url= : URL to download animations.json from}
                            {--days=30 : Number of days ahead to pre-download animations for}';

    protected $description = 'Pre-download upcoming splash animations and write schedule-local.json for on-device date resolution';

    public function handle(MobileSplashscreen $splash): int
    {
        $url = $this->option('url');
        $days = (int) $this->option('days');

        if ($url) {
            $this->info("Fetching animations.json from: {$url}");

            try {
                $splash->syncFromUrl($url);
                $this->info('animations.json downloaded.');
            } catch (\Throwable $e) {
                $this->error("Failed to fetch animations.json: {$e->getMessage()}");

                return self::FAILURE;
            }
        }

        try {
            $count = $splash->resolveActive($days);
        } catch (\Throwable $e) {
            $this->error("Failed to resolve schedule: {$e->getMessage()}");

            return self::FAILURE;
        }

        if ($count > 0) {
            $this->info("schedule-local.json written with {$count} upcoming ".($count === 1 ? 'entry' : 'entries')." (lookahead: {$days} days).");
        } else {
            $this->line("No upcoming entries within {$days} days — schedule-local.json cleared.");
        }

        return self::SUCCESS;
    }
}
