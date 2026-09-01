<?php

namespace Tests\Feature;

use App\Models\Entry;
use App\Models\Player;
use App\Models\Season;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CleanupOrphanEntriesTest extends TestCase
{
    use RefreshDatabase;

    private Season $season;

    protected function setUp(): void
    {
        parent::setUp();
        $this->season = Season::create([
            'label' => '2026 Second Game Pool', 'entry_fee' => 1, 'start_week' => 1, 'total_weeks' => 33,
        ]);
    }

    public function test_it_deletes_entries_whose_player_no_longer_exists(): void
    {
        $real = Player::create(['season_id' => $this->season->id, 'name' => 'Real, Ray', 'team' => 'T1']);
        Entry::create(['player_id' => $real->id, 'week_number' => 1, 'amount' => 1, 'status' => 'paid']);

        // Force in an orphan. RefreshDatabase keeps us inside a transaction, so
        // toggling foreign_keys is a no-op on SQLite — defer the checks instead
        // (they'd fire at COMMIT, which the rollback never reaches).
        $this->makeOrphan('00000000-0000-4000-8000-000000000000');

        $this->assertDatabaseCount('entries', 2);

        $this->artisan('entries:cleanup-orphans --force')->assertSuccessful();

        $this->assertDatabaseCount('entries', 1);
        $this->assertEquals(1, Entry::where('player_id', $real->id)->count());
    }

    private function makeOrphan(string $playerId): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            DB::statement('PRAGMA defer_foreign_keys = ON');
        } else {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
        }

        Entry::create(['player_id' => $playerId, 'week_number' => 1, 'amount' => 1, 'status' => 'paid']);

        if ($driver !== 'sqlite') {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    public function test_it_is_a_noop_when_there_are_no_orphans(): void
    {
        $real = Player::create(['season_id' => $this->season->id, 'name' => 'Real, Ray', 'team' => 'T1']);
        Entry::create(['player_id' => $real->id, 'week_number' => 1, 'amount' => 1, 'status' => 'paid']);

        $this->artisan('entries:cleanup-orphans --force')
            ->expectsOutputToContain('No orphaned entries')
            ->assertSuccessful();

        $this->assertDatabaseCount('entries', 1);
    }
}
