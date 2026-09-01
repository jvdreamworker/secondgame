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

    private function import(UploadedFile $file)
    {
        return $this->actingAs($this->operator)
            ->post('/api/players/import', ['file' => $file], ['Accept' => 'application/json']);
    }

    public function test_import_requires_authentication(): void
    {
        $this->postJson('/api/players/import')->assertUnauthorized();
    }

    public function test_it_imports_new_players_and_reports_counts(): void
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

        $this->import($file)
            ->assertOk()
            ->assertJson(['imported' => 3, 'updated' => 0, 'deactivated' => 0, 'skipped' => 1]);

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

        $this->import($file)->assertOk()->assertJson(['imported' => 3, 'skipped' => 0]);

        $this->assertDatabaseHas('players', ['name' => 'Vancleave, Charles', 'team_number' => '9', 'team' => 'Team 9']);
        $this->assertDatabaseHas('players', ['name' => 'Rhodes, Candi', 'team_number' => '11', 'team' => 'Team 11']);
        $this->assertDatabaseHas('players', ['name' => 'Hamel, John', 'team' => 'Substitute']);
        $this->assertDatabaseMissing('players', ['team' => '—']);
    }

    public function test_column_one_is_stored_verbatim_as_the_full_name(): void
    {
        $file = $this->xlsx([
            ['Barber, Jb', 7, '175', 'Pin Pals'],
            ['Van Der Berg, Mary Ann', 3, '140', 'Team 3'],
        ]);

        $this->import($file)->assertOk()->assertJson(['imported' => 2, 'skipped' => 0]);

        $this->assertDatabaseHas('players', ['name' => 'Barber, Jb', 'team_number' => '7', 'team' => 'Pin Pals']);
        $this->assertDatabaseHas('players', ['name' => 'Van Der Berg, Mary Ann', 'team_number' => '3', 'team' => 'Team 3']);
        $this->assertDatabaseMissing('players', ['name' => 'Barber']);
        $this->assertDatabaseMissing('players', ['team_number' => 'Jb']);
    }

    public function test_re_import_matches_by_name_and_team_number_and_keeps_the_id_and_entries(): void
    {
        $bob = Player::create([
            'season_id' => $this->season->id, 'name' => 'Bell, Bob', 'team_number' => '4', 'team' => 'Team 4', 'active' => false,
        ]);
        Entry::create(['player_id' => $bob->id, 'week_number' => 1, 'amount' => 1, 'status' => 'paid']);
        Entry::create(['player_id' => $bob->id, 'week_number' => 2, 'amount' => 1, 'status' => 'covered']);

        $file = $this->xlsx([
            ['Bell, Bob', 4, '', 'Team 4 Renamed'],   // matches Bob -> update in place, reactivate
            ['Bell, Sue', 4, '', 'Team 4 Renamed'],   // new
        ]);

        $this->import($file)->assertOk()->assertJson(['imported' => 1, 'updated' => 1, 'deactivated' => 0]);

        // same row, same id, entries intact, reactivated, team refreshed
        $this->assertDatabaseHas('players', [
            'id' => $bob->id, 'name' => 'Bell, Bob', 'team_number' => '4', 'team' => 'Team 4 Renamed', 'active' => true,
        ]);
        $this->assertDatabaseCount('entries', 2);
        $this->assertEquals(2, Entry::where('player_id', $bob->id)->count());
        $this->assertDatabaseCount('players', 2);
    }

    public function test_reimporting_the_same_file_changes_nothing(): void
    {
        $rows = [
            ['Aponte, Juan', 13, '', 'Team 13'],
            ['Austile, Darian', 1, '', 'Terminators'],
        ];

        $this->import($this->xlsx($rows))->assertOk()->assertJson(['imported' => 2, 'updated' => 0]);
        $this->import($this->xlsx($rows))->assertOk()->assertJson(['imported' => 0, 'updated' => 2, 'deactivated' => 0]);

        $this->assertDatabaseCount('players', 2);
        $this->assertEquals(2, Player::where('active', true)->count());
    }

    public function test_a_unique_name_still_matches_when_the_team_number_changed(): void
    {
        $al = Player::create([
            'season_id' => $this->season->id, 'name' => 'Smith, Al', 'team_number' => '3', 'team' => 'Team 3',
        ]);
        Entry::create(['player_id' => $al->id, 'week_number' => 1, 'amount' => 1, 'status' => 'paid']);

        $this->import($this->xlsx([['Smith, Al', 7, '', 'Team 7']]))
            ->assertOk()
            ->assertJson(['imported' => 0, 'updated' => 1]);

        $this->assertDatabaseHas('players', ['id' => $al->id, 'team_number' => '7', 'team' => 'Team 7']);
        $this->assertEquals(1, Entry::where('player_id', $al->id)->count());
    }

    public function test_players_absent_from_the_file_are_deactivated_not_deleted(): void
    {
        $keep = Player::create(['season_id' => $this->season->id, 'name' => 'Kept, Kim', 'team_number' => '1', 'team' => 'T1']);
        $gone = Player::create(['season_id' => $this->season->id, 'name' => 'Gone, Gil', 'team_number' => '2', 'team' => 'T2']);
        Entry::create(['player_id' => $gone->id, 'week_number' => 1, 'amount' => 1, 'status' => 'paid']);

        $this->import($this->xlsx([['Kept, Kim', 1, '', 'T1']]))
            ->assertOk()
            ->assertJson(['imported' => 0, 'updated' => 1, 'deactivated' => 1]);

        $this->assertDatabaseHas('players', ['id' => $keep->id, 'active' => true]);
        $this->assertDatabaseHas('players', ['id' => $gone->id, 'active' => false]);   // still there
        $this->assertEquals(1, Entry::where('player_id', $gone->id)->count());          // entry kept
    }

    public function test_a_non_spreadsheet_upload_is_rejected_clearly(): void
    {
        $junk = UploadedFile::fake()->createWithContent('roster.xlsx', 'not a spreadsheet at all');

        $this->import($junk)
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
