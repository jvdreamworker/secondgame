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
     * Columns read from the sheet: 1 = name, 2 = team_number, 4 = team.
     * A row is skipped when an exact (name + team_number + team) match
     * already exists — either in this season or earlier in the same file.
     * Everything imported is set active.
     *
     * Response: { imported, skipped, players: [ ...bundle player shape... ] }
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:5120'],
        ]);

        $season = Season::current();
        abort_if($season === null, 409, 'No season configured.');

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
            $name = $this->cell($row, 0);
            $teamNumber = $this->cell($row, 1);
            $team = $this->cell($row, 3);

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
            'players' => $created,
        ]);
    }

    private function cell(array $row, int $index): string
    {
        return trim((string) ($row[$index] ?? ''));
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
