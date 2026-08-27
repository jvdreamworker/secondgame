<?php

namespace Tests\Feature;

use App\Models\Entry;
use App\Models\Player;
use App\Models\Season;
use App\Models\User;
use App\Models\WeeklyResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PoolApiTest extends TestCase
{
    use RefreshDatabase;

    private Season $season;
    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->season = Season::create([
            'label' => '2026 Second Game Pool',
            'entry_fee' => 1,
            'start_week' => 1,
            'total_weeks' => 33,
        ]);
        $this->operator = User::factory()->create();
    }

    public function test_api_requires_authentication(): void
    {
        $this->getJson("/api/seasons/{$this->season->id}/bundle")->assertUnauthorized();
    }

    public function test_bundle_returns_the_frontend_shape(): void
    {
        $player = Player::create([
            'season_id' => $this->season->id, 'name' => 'Bell, Bob', 'team_number' => '4', 'team' => 'Team 4', 'active' => true,
        ]);
        Entry::create([
            'player_id' => $player->id, 'week_number' => 1, 'status' => 'paid', 'amount' => 1, 'note' => '',
        ]);
        WeeklyResult::create([
            'season_id' => $this->season->id, 'week_number' => 1, 'score' => '187?', 'payout' => 0, 'note' => '',
        ]);

        $this->actingAs($this->operator)
            ->getJson("/api/seasons/{$this->season->id}/bundle")
            ->assertOk()
            ->assertJson([
                'config' => [
                    'seasonLabel' => '2026 Second Game Pool',
                    'entryFee' => 1,
                    'startWeek' => 1,
                    'totalWeeks' => 33,
                ],
                'players' => [
                    ['id' => $player->id, 'name' => 'Bell, Bob', 'team_number' => '4', 'team' => 'Team 4', 'active' => true],
                ],
                'entries' => [
                    ['id' => $player->id.':1', 'player_id' => $player->id, 'week' => 1, 'amount' => 1, 'status' => 'paid'],
                ],
                'results' => [
                    ['week' => 1, 'score' => '187?', 'winner_player_id' => null, 'payout' => 0],
                ],
            ]);
    }

    public function test_create_player_is_idempotent_on_client_uuid(): void
    {
        $id = (string) Str::uuid();
        $body = ['id' => $id, 'name' => 'New, Player', 'team_number' => '7', 'team' => 'Subs', 'active' => true];

        $this->actingAs($this->operator)->postJson('/api/players', $body)
            ->assertCreated()
            ->assertJson(['id' => $id, 'name' => 'New, Player', 'team_number' => '7', 'team' => 'Subs']);
        $this->actingAs($this->operator)->postJson('/api/players', $body)->assertCreated(); // retry, no dupe

        $this->assertDatabaseCount('players', 1);
        $this->assertDatabaseHas('players', ['id' => $id, 'season_id' => $this->season->id, 'team_number' => '7', 'team' => 'Subs']);
    }

    public function test_entry_upsert_maps_week_to_week_number_and_upserts(): void
    {
        $player = Player::create(['season_id' => $this->season->id, 'name' => 'X', 'team' => 'T', 'active' => true]);

        $this->actingAs($this->operator)->putJson('/api/entries', [
            'player_id' => $player->id, 'week' => 4, 'amount' => 5, 'status' => 'paid', 'note' => 'lump sum',
        ])->assertOk()->assertJson(['id' => $player->id.':4', 'week' => 4, 'status' => 'paid']);

        $this->actingAs($this->operator)->putJson('/api/entries', [
            'player_id' => $player->id, 'week' => 4, 'amount' => null, 'status' => 'covered', 'note' => '',
        ])->assertOk();

        $this->assertDatabaseCount('entries', 1);
        $this->assertDatabaseHas('entries', ['player_id' => $player->id, 'week_number' => 4, 'status' => 'covered']);
    }

    public function test_entry_delete_is_forgiving(): void
    {
        $player = Player::create(['season_id' => $this->season->id, 'name' => 'X', 'team' => 'T', 'active' => true]);

        $this->actingAs($this->operator)->deleteJson("/api/entries/{$player->id}/9")->assertNoContent();
        Entry::create(['player_id' => $player->id, 'week_number' => 9, 'status' => 'paid', 'amount' => 1, 'note' => '']);
        $this->actingAs($this->operator)->deleteJson("/api/entries/{$player->id}/9")->assertNoContent();
        $this->assertDatabaseCount('entries', 0);
    }

    public function test_weekly_result_upsert_on_current_season(): void
    {
        $winner = Player::create(['season_id' => $this->season->id, 'name' => 'W', 'team' => 'T', 'active' => true]);

        $this->actingAs($this->operator)->putJson('/api/weekly-results/7', [
            'week' => 7, 'score' => '145', 'winner_player_id' => $winner->id, 'payout' => 12, 'note' => '',
        ])->assertOk()->assertJson(['week' => 7, 'score' => '145', 'winner_player_id' => $winner->id, 'payout' => 12]);

        $this->actingAs($this->operator)->putJson('/api/weekly-results/7', [
            'week' => 7, 'score' => '145', 'winner_player_id' => null, 'payout' => 0, 'note' => 'reversed',
        ])->assertOk();

        $this->assertDatabaseCount('weekly_results', 1);
        $this->assertDatabaseHas('weekly_results', ['week_number' => 7, 'winner_player_id' => null, 'note' => 'reversed']);
    }

    public function test_config_patch_maps_camelcase_to_columns(): void
    {
        $this->actingAs($this->operator)->patchJson('/api/config', [
            'seasonLabel' => 'Renamed', 'entryFee' => 2, 'startWeek' => 3, 'totalWeeks' => 30,
        ])->assertOk()->assertJson([
            'seasonLabel' => 'Renamed', 'entryFee' => 2, 'startWeek' => 3, 'totalWeeks' => 30,
        ]);

        $this->assertDatabaseHas('seasons', [
            'id' => $this->season->id, 'label' => 'Renamed', 'entry_fee' => 2, 'start_week' => 3, 'total_weeks' => 30,
        ]);
    }

    public function test_pool_page_redirects_guests_to_login(): void
    {
        $this->get('/pool')->assertRedirect('/login');
    }
}
