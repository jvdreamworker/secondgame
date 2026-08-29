<?php

namespace Tests\Feature;

use App\Models\Entry;
use App\Models\Player;
use App\Models\Season;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlushRosterTest extends TestCase
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

    public function test_force_deletes_all_players_and_their_entries(): void
    {
        $a = Player::create(['season_id' => $this->season->id, 'name' => 'A, One', 'team' => 'T1']);
        Entry::create(['player_id' => $a->id, 'week_number' => 1, 'amount' => 1, 'status' => 'paid']);
        Player::create(['season_id' => $this->season->id, 'name' => 'B, Two', 'team' => 'T2']);

        $this->artisan('players:flush --force')->assertSuccessful();

        $this->assertDatabaseCount('players', 0);
        $this->assertDatabaseCount('entries', 0);
    }

    public function test_it_is_a_noop_when_the_roster_is_already_empty(): void
    {
        $this->artisan('players:flush --force')
            ->expectsOutputToContain('already has no players')
            ->assertSuccessful();
    }
}
