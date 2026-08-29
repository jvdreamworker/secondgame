<?php

namespace App\Console\Commands;

use App\Models\Season;
use Illuminate\Console\Command;

class FlushRoster extends Command
{
    /**
     * Deletes every player in a season so the roster can be re-imported from
     * scratch. Their entries cascade away and they are cleared from any
     * weekly result's winner slot. Weekly result rows themselves are kept.
     */
    protected $signature = 'players:flush
                            {--season= : Season id (defaults to the current season)}
                            {--force : Skip the confirmation prompt}';

    protected $description = 'Delete all players in a season (and their entries) for a clean re-import';

    public function handle(): int
    {
        $season = $this->option('season')
            ? Season::find($this->option('season'))
            : Season::current();

        if (! $season) {
            $this->error('No season found.');

            return self::FAILURE;
        }

        $players = $season->players()->get();

        if ($players->isEmpty()) {
            $this->info("Season {$season->id} ({$season->label}) already has no players.");

            return self::SUCCESS;
        }

        if (! $this->option('force')
            && ! $this->confirm("Delete all {$players->count()} players in season {$season->id} ({$season->label})? Their entries go too.")) {
            $this->comment('Aborted.');

            return self::SUCCESS;
        }

        $players->each->delete();

        $this->info("Deleted {$players->count()} players from season {$season->id}.");

        return self::SUCCESS;
    }
}
