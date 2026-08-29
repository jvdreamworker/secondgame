<?php

namespace App\Console\Commands;

use App\Models\Player;
use App\Models\Season;
use App\Models\WeeklyResult;
use Illuminate\Console\Command;

class PurgeSplitNamePlayers extends Command
{
    /**
     * Roster names are always "Last, First". An earlier build of the XLSX
     * import split that on the comma, creating players whose name has no comma
     * (just a last name, or just a first name). This command removes those so
     * the roster can be re-imported cleanly.
     */
    protected $signature = 'players:purge-split-names
                            {--season= : Season id (defaults to the current season)}
                            {--force : Actually delete (otherwise this is a dry run)}';

    protected $description = 'Delete players whose name has no comma (split-name import artifacts)';

    public function handle(): int
    {
        $season = $this->option('season')
            ? Season::find($this->option('season'))
            : Season::current();

        if (! $season) {
            $this->error('No season found.');

            return self::FAILURE;
        }

        $suspects = $season->players()
            ->where('name', 'not like', '%,%')
            ->orderBy('name')
            ->get();

        if ($suspects->isEmpty()) {
            $this->info("No comma-less player names in season {$season->id} ({$season->label}). Nothing to do.");

            return self::SUCCESS;
        }

        // Never touch a row that has already been used in the pool.
        $winnerIds = WeeklyResult::where('season_id', $season->id)
            ->whereNotNull('winner_player_id')
            ->pluck('winner_player_id')
            ->flip();

        $deletable = $suspects->filter(
            fn (Player $p) => $p->entries()->count() === 0 && ! $winnerIds->has($p->id)
        );
        $inUse = $suspects->reject(
            fn (Player $p) => $p->entries()->count() === 0 && ! $winnerIds->has($p->id)
        );

        $this->table(
            ['id', 'name', 'team_number', 'team', 'status'],
            $suspects->map(fn (Player $p) => [
                $p->id,
                $p->name,
                $p->team_number,
                $p->team,
                $deletable->contains($p) ? 'will delete' : 'SKIP (has entries / is a winner)',
            ])->all()
        );

        if ($inUse->isNotEmpty()) {
            $this->warn("{$inUse->count()} row(s) are referenced by entries or results and will be left alone. Fix those by hand.");
        }

        if (! $this->option('force')) {
            $this->comment("Dry run. {$deletable->count()} player(s) would be deleted. Re-run with --force to apply.");

            return self::SUCCESS;
        }

        $count = 0;
        foreach ($deletable as $player) {
            $player->delete();
            $count++;
        }

        $this->info("Deleted {$count} split-name player(s) from season {$season->id}.");

        return self::SUCCESS;
    }
}
