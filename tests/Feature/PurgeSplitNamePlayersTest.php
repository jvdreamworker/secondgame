<?php

namespace Tests\Feature;

use App\Models\Entry;
use App\Models\Player;
use App\Models\Season;
use App\Models\WeeklyResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurgeSplitNamePlayersTest extends TestCase
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

    public function test_dry_run_lists_but_does_not_delete(): void
    {
        Player::create(['season_id' => $this->season->id, 'name' => 'Barber', 'team' => 'Pin Pals']);
        Player::create(['season_id' => $this->season->id, 'name' => 'Barber, Jb', 'team' => 'Pin Pals']);

        $this->artisan('players:purge-split-names')
            ->assertSuccessful();

        $this->assertDatabaseCount('players', 2);
    }

    public function test_force_deletes_only_comma_less_names_with_no_pool_activity(): void
    {
        $split = Player::create(['season_id' => $this->season->id, 'name' => 'Barber', 'team' => 'Pin Pals']);
        $good = Player::create(['season_id' => $this->season->id, 'name' => 'Barber, Jb', 'team' => 'Pin Pals']);

        $used = Player::create(['season_id' => $this->season->id, 'name' => 'Stokes', 'team' => 'Team 2']);
        Entry::create(['player_id' => $used->id, 'week_number' => 1, 'amount' => 1, 'status' => 'paid']);

        $winner = Player::create(['season_id' => $this->season->id, 'name' => 'Bell', 'team' => 'Team 4']);
        WeeklyResult::create([
            'season_id' => $this->season->id, 'week_number' => 2, 'winner_player_id' => $winner->id, 'payout' => 5,
        ]);

        $this->artisan('players:purge-split-names --force')
            ->assertSuccessful();

        $this->assertDatabaseMissing('players', ['id' => $split->id]);
        $this->assertDatabaseHas('players', ['id' => $good->id]);
        $this->assertDatabaseHas('players', ['id' => $used->id]);   // has an entry -> left alone
        $this->assertDatabaseHas('players', ['id' => $winner->id]); // is a winner -> left alone
    }
}
