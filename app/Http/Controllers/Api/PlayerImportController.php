<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Player;
use App\Models\Season;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;

class PlayerImportController extends Controller
{
    /**
     * POST /api/players/import  (multipart: file=<.xlsx>)
     *
     * Columns: 1 = name, 2 = team_number. The team NAME is taken from column 3
     * if it holds text, otherwise column 4 — so both the current 3-column
     * export (name, team#, team) and the older 4-column one (name, team#, avg,
     * team) import correctly.
     *
     * A row is skipped when an exact (name + team_number + team) match
     * already exists — either in this season or earlier in the same file.
     * Everything imported is set active.
     *
     * Pass replace=1 to wipe the season's existing roster first (this also
     * deletes those players' entries and clears them from any weekly result).
     *
     * Response: { imported, skipped, replaced, players: [ ...bundle player shape... ] }
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:5120'],
            'replace' => ['sometimes', 'boolean'],
        ]);

        $season = Season::current();
        abort_if($season === null, 409, 'No season configured.');

        $replaced = 0;
        if ($request->boolean('replace')) {
            // Delete row-by-row so FKs fire: entries cascade away, and
            // weekly_results.winner_player_id is nulled.
            $current = $season->players()->get();
            $current->each->delete();
            $replaced = $current->count();
        }

        $path = $request->file('file')->getRealPath();
        $reader = new XlsxReader();
        $reader->setReadDataOnly(true);

        if (! $reader->canRead($path)) {
            return response()->json(['message' => 'Could not read that spreadsheet.'], 422);
        }

        try {
            $sheet = $reader->load($path)->getActiveSheet();
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Could not read that spreadsheet.'], 422);
        }

        $rows = $sheet->toArray(null, true, false, false);

        // Exact tuples already on record for this season.
        $existing = $season->players()
            ->get(['name', 'team_number', 'team'])
            ->map(fn ($p) => $this->key($p->name, $p->team_number, $p->team))
            ->flip();

        $imported = 0;
        $skipped = 0;
        $created = [];
        $seenInFile = [];

        foreach ($rows as $row) {
            // Column 1 is the whole name, stored exactly as it appears in the
            // sheet ("Last, First"). Never split or reformat it — the comma is
            // part of the name, not a delimiter.
            $name = $this->cell($row, 0);
            $teamNumber = $this->cell($row, 1);
            $team = $this->teamName($row);

            if ($name === '' || $this->looksLikeHeader($name)) {
                continue;
            }

            $key = $this->key($name, $teamNumber, $team);
            if ($existing->has($key) || isset($seenInFile[$key])) {
                $skipped++;
                continue;
            }
            $seenInFile[$key] = true;

            $player = Player::create([
                'id' => (string) Str::uuid(),
                'season_id' => $season->id,
                'name' => $name,
                'team_number' => $teamNumber !== '' ? $teamNumber : null,
                'team' => $team !== '' ? $team : '—',
                'active' => true,
            ]);

            $created[] = [
                'id' => $player->id,
                'name' => $player->name,
                'team_number' => $player->team_number,
                'team' => $player->team ?? '—',
                'active' => true,
            ];
            $imported++;
        }

        return response()->json([
            'imported' => $imported,
            'skipped' => $skipped,
            'replaced' => $replaced,
            'players' => $created,
        ]);
    }

    private function cell(array $row, int $index): string
    {
        return trim((string) ($row[$index] ?? ''));
    }

    /**
     * Team name: column 3 normally. If column 3 is empty or purely numeric
     * (an "average" column in the old 4-column layout) and column 4 has a
     * value, use column 4 instead.
     */
    private function teamName(array $row): string
    {
        $c = $this->cell($row, 2);
        $d = $this->cell($row, 3);

        if ($d !== '' && ($c === '' || is_numeric($c))) {
            return $d;
        }

        return $c;
    }

    private function key(?string $name, ?string $teamNumber, ?string $team): string
    {
        return implode("\0", [
            trim((string) $name),
            trim((string) $teamNumber),
            trim((string) $team),
        ]);
    }

    private function looksLikeHeader(string $name): bool
    {
        return (bool) preg_match('/^(name|player|bowler|last,?\s*first)$/i', trim($name));
    }
}
