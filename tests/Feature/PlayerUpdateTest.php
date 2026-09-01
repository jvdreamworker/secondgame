<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\Season;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlayerUpdateTest extends TestCase
{
    use RefreshDatabase;

    private Season $season;
    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->season = Season::create([
            'label' => '2026 Second Game Pool', 'entry_fee' => 1, 'start_week' => 1, 'total_weeks' => 33,
        ]);
        $this->operator = User::factory()->create();
    }

    public function test_patch_player_updates_name_team_number_and_team(): void
    {
        $p = Player::create(['season_id' => $this->season->id, 'name' => 'Old, Name', 'team_number' => '3', 'team' => 'Team 3']);

        $this->actingAs($this->operator)
            ->patchJson("/api/players/{$p->id}", ['name' => 'New, Name', 'team_number' => '9', 'team' => 'Team 9'])
            ->assertOk()
            ->assertJson(['id' => $p->id, 'name' => 'New, Name', 'team_number' => '9', 'team' => 'Team 9']);

        $this->assertDatabaseHas('players', ['id' => $p->id, 'name' => 'New, Name', 'team_number' => '9', 'team' => 'Team 9']);
    }

    public function test_rename_team_updates_every_player_with_that_team_number(): void
    {
        $a = Player::create(['season_id' => $this->season->id, 'name' => 'A, A', 'team_number' => '9', 'team' => 'Team 9']);
        $b = Player::create(['season_id' => $this->season->id, 'name' => 'B, B', 'team_number' => '9', 'team' => 'Team 9']);
        $c = Player::create(['season_id' => $this->season->id, 'name' => 'C, C', 'team_number' => '10', 'team' => 'Team 10']);

        $this->actingAs($this->operator)
            ->patchJson('/api/players/team/9/name', ['team' => 'The Gutter Rats'])
            ->assertOk()
            ->assertJson(['updated' => 2]);

        $this->assertDatabaseHas('players', ['id' => $a->id, 'team' => 'The Gutter Rats']);
        $this->assertDatabaseHas('players', ['id' => $b->id, 'team' => 'The Gutter Rats']);
        $this->assertDatabaseHas('players', ['id' => $c->id, 'team' => 'Team 10']);   // untouched
    }

    public function test_rename_team_requires_a_name(): void
    {
        $this->actingAs($this->operator)
            ->patchJson('/api/players/team/9/name', [])
            ->assertStatus(422);
    }

    public function test_rename_team_only_touches_the_current_season(): void
    {
        // Season::current() is the newest season, so make one after setUp's.
        $current = Season::create(['label' => '2027', 'entry_fee' => 1, 'start_week' => 1, 'total_weeks' => 33]);
        $prior = Player::create(['season_id' => $this->season->id, 'name' => 'Z, Z', 'team_number' => '9', 'team' => 'Team 9']);
        $now = Player::create(['season_id' => $current->id, 'name' => 'Y, Y', 'team_number' => '9', 'team' => 'Team 9']);

        $this->actingAs($this->operator)
            ->patchJson('/api/players/team/9/name', ['team' => 'Renamed'])
            ->assertOk()
            ->assertJson(['updated' => 1]);

        $this->assertDatabaseHas('players', ['id' => $now->id, 'team' => 'Renamed']);
        $this->assertDatabaseHas('players', ['id' => $prior->id, 'team' => 'Team 9']);
    }

    public function test_player_endpoints_require_auth(): void
    {
        $p = Player::create(['season_id' => $this->season->id, 'name' => 'A, A', 'team_number' => '9', 'team' => 'Team 9']);
        $this->patchJson("/api/players/{$p->id}", ['name' => 'x'])->assertUnauthorized();
        $this->patchJson('/api/players/team/9/name', ['team' => 'x'])->assertUnauthorized();
    }
}
