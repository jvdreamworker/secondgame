<?php

namespace Tests\Feature;

use App\Models\Entry;
use App\Models\Player;
use App\Models\Season;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class PlayerImportTest extends TestCase
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

    public function test_import_requires_authentication(): void
    {
        $this->postJson('/api/players/import')->assertUnauthorized();
    }

    public function test_it_imports_players_and_reports_counts(): void
    {
        // old 4-column layout: name, team_number, avg, team
        $file = $this->xlsx([
            ['Name', 'Team #', 'Avg', 'Team'],            // header, skipped
            ['Bell, Bob', 4, '182', 'Team 4'],
            ['Bell, Ingrid', 4, '151', 'Team 4'],
            ['Bell, Bob', 4, '182', 'Team 4'],            // duplicate within the file
            ['', 9, '', 'Team 9'],                        // blank name, skipped
            ['Stokes, Joe', 2, '164', 'Team 2'],
        ]);

        $response = $this->actingAs($this->operator)
            ->post('/api/players/import', ['file' => $file], ['Accept' => 'application/json'])
            ->assertOk();

        $response->assertJson(['imported' => 3, 'skipped' => 1]);
        $this->assertCount(3, $response->json('players'));

        $this->assertDatabaseCount('players', 3);
        $this->assertDatabaseHas('players', [
            'name' => 'Bell, Bob', 'team_number' => '4', 'team' => 'Team 4', 'active' => true, 'season_id' => $this->season->id,
        ]);
        $this->assertDatabaseHas('players', ['name' => 'Stokes, Joe', 'team_number' => '2', 'team' => 'Team 2']);
    }

    public function test_it_reads_team_name_from_column_three_in_the_clean_export(): void
    {
        // current 3-column layout: name, team_number, team name
        $file = $this->xlsx([
            ['Name', 'Team #', 'Team'],       // header, skipped
            ['Vancleave, Charles', 9, 'Team 9'],
            ['Rhodes, Candi', 11, 'Team 11'],
            ['Hamel, John', '', 'Substitute'],
        ]);

        $this->actingAs($this->operator)
            ->post('/api/players/import', ['file' => $file], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJson(['imported' => 3, 'skipped' => 0]);

        $this->assertDatabaseHas('players', ['name' => 'Vancleave, Charles', 'team_number' => '9', 'team' => 'Team 9']);
        $this->assertDatabaseHas('players', ['name' => 'Rhodes, Candi', 'team_number' => '11', 'team' => 'Team 11']);
        $this->assertDatabaseHas('players', ['name' => 'Hamel, John', 'team' => 'Substitute']);
        $this->assertDatabaseMissing('players', ['team' => '—']);
    }

    public function test_column_one_is_stored_verbatim_as_the_full_name(): void
    {
        // Names arrive "Last, First". The whole cell is the player name — it is
        // never split on the comma, and column 2 stays the team number.
        $file = $this->xlsx([
            ['Barber, Jb', 7, '175', 'Pin Pals'],
            ['Van Der Berg, Mary Ann', 3, '140', 'Team 3'],
        ]);

        $this->actingAs($this->operator)
            ->post('/api/players/import', ['file' => $file], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJson(['imported' => 2, 'skipped' => 0]);

        $this->assertDatabaseHas('players', [
            'name' => 'Barber, Jb', 'team_number' => '7', 'team' => 'Pin Pals',
        ]);
        $this->assertDatabaseHas('players', [
            'name' => 'Van Der Berg, Mary Ann', 'team_number' => '3', 'team' => 'Team 3',
        ]);
        $this->assertDatabaseMissing('players', ['name' => 'Barber']);
        $this->assertDatabaseMissing('players', ['name' => 'Jb']);
        $this->assertDatabaseMissing('players', ['team_number' => 'Jb']);
    }

    public function test_it_skips_rows_that_already_exist_in_the_season(): void
    {
        Player::create([
            'season_id' => $this->season->id, 'name' => 'Bell, Bob', 'team_number' => '4', 'team' => 'Team 4', 'active' => false,
        ]);

        $file = $this->xlsx([
            ['Bell, Bob', 4, '', 'Team 4'],   // exact match -> skipped (and NOT reactivated)
            ['Bell, Bob', 5, '', 'Team 4'],   // different team_number -> imported
        ]);

        $this->actingAs($this->operator)
            ->post('/api/players/import', ['file' => $file], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJson(['imported' => 1, 'skipped' => 1]);

        $this->assertDatabaseCount('players', 2);
        // the pre-existing inactive row is untouched
        $this->assertDatabaseHas('players', ['name' => 'Bell, Bob', 'team_number' => '4', 'active' => false]);
        $this->assertDatabaseHas('players', ['name' => 'Bell, Bob', 'team_number' => '5', 'active' => true]);
    }

    public function test_reimporting_the_same_file_imports_nothing(): void
    {
        $rows = [
            ['Aponte, Juan', 13, '', 'Team 13'],
            ['Austile, Darian', 1, '', 'Terminators'],
        ];

        $this->actingAs($this->operator)
            ->post('/api/players/import', ['file' => $this->xlsx($rows)], ['Accept' => 'application/json'])
            ->assertOk()->assertJson(['imported' => 2, 'skipped' => 0]);

        $this->actingAs($this->operator)
            ->post('/api/players/import', ['file' => $this->xlsx($rows)], ['Accept' => 'application/json'])
            ->assertOk()->assertJson(['imported' => 0, 'skipped' => 2]);

        $this->assertDatabaseCount('players', 2);
    }

    public function test_replace_wipes_the_existing_roster_first(): void
    {
        $stale = Player::create([
            'season_id' => $this->season->id, 'name' => 'Old, Player', 'team_number' => '1', 'team' => 'Team 1',
        ]);
        Entry::create(['player_id' => $stale->id, 'week_number' => 1, 'amount' => 1, 'status' => 'paid']);

        $file = $this->xlsx([
            ['Barber, Jb', 7, '175', 'Pin Pals'],
            ['Chen, Amy', 7, '160', 'Pin Pals'],
        ]);

        $this->actingAs($this->operator)
            ->post('/api/players/import', ['file' => $file, 'replace' => '1'], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJson(['imported' => 2, 'skipped' => 0, 'replaced' => 1]);

        $this->assertDatabaseCount('players', 2);
        $this->assertDatabaseMissing('players', ['id' => $stale->id]);
        $this->assertDatabaseMissing('entries', ['player_id' => $stale->id]);
        $this->assertDatabaseHas('players', ['name' => 'Barber, Jb']);
    }

    public function test_a_non_spreadsheet_upload_is_rejected_clearly(): void
    {
        $junk = UploadedFile::fake()->createWithContent('roster.xlsx', 'not a spreadsheet at all');

        $this->actingAs($this->operator)
            ->post('/api/players/import', ['file' => $junk], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJson(['message' => 'Could not read that spreadsheet.']);
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    private function xlsx(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($rows as $r => $row) {
            foreach ($row as $c => $value) {
                $sheet->setCellValue([$c + 1, $r + 1], $value);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'import_').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile(
            $path,
            'roster.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );
    }
}
