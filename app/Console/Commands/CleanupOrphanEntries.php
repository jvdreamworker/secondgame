<?php

namespace App\Console\Commands;

use App\Models\Entry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupOrphanEntries extends Command
{
    /**
     * Deletes entries whose player_id no longer exists in the players table.
     * The FK on entries.player_id is ON DELETE CASCADE, so these shouldn't
     * occur through normal Eloquent deletes — this is a safety net for rows
     * left behind by a raw import, a FK-checks-off window, or an older build.
     */
    protected $signature = 'entries:cleanup-orphans {--force : Skip the confirmation prompt}';

    protected $description = 'Delete entries that reference a player_id with no matching players row';

    public function handle(): int
    {
        $orphans = Entry::query()
            ->whereNotExists(fn ($q) => $q
                ->select(DB::raw(1))
                ->from('players')
                ->whereColumn('players.id', 'entries.player_id'))
            ->get();

        if ($orphans->isEmpty()) {
            $this->info('No orphaned entries. Nothing to do.');

            return self::SUCCESS;
        }

        $this->table(
            ['entry id', 'player_id (missing)', 'week', 'amount', 'status'],
            $orphans->map(fn (Entry $e) => [$e->id, $e->player_id, $e->week_number, $e->amount, $e->status])->all()
        );

        if (! $this->option('force')
            && ! $this->confirm("Delete these {$orphans->count()} orphaned entr(y|ies)?")) {
            $this->comment('Aborted.');

            return self::SUCCESS;
        }

        $deleted = Entry::whereIn('id', $orphans->pluck('id'))->delete();
        $this->info("Deleted {$deleted} orphaned entr(y|ies).");

        return self::SUCCESS;
    }
}
