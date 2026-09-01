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
     * Columns: 1 = name, 2 = team_number. The team NAME is column 3 if it
     * holds text, otherwise column 4 — so both the current 3-column export
     * (name, team#, team) and the older 4-column one (name, team#, avg, team)
     * import correctly.
     *
     * This is a MERGE, safe to run mid-season:
     *   - an incoming row is matched to an existing season player by
     *     (name + team_number), falling back to a unique name match;
     *   - a match is updated in place (name, team, active = true) — its id
     *     and all its entries are untouched;
     *   - a row with no match is inserted as a new player;
     *   - a season player absent from the file is deactivated (never deleted,
     *     so payment history survives).
     *
     * Response: { imported, updated, deactivated, skipped, players: [...] }
     * where `players` is every row that changed, in the bundle player shape.
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

        $seasonPlayers = $season->players()->get();
        $byPair = $seasonPlayers->keyBy(fn ($p) => $this->pairKey($p->name, $p->team_number));
        $byName = $seasonPlayers->groupBy(fn ($p) => $this->nameKey($p->name));

        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $matched = [];   // player id => true, for the "deactivate the rest" pass
        $touched = [];    // bundle payloads for every row we changed
        $seenInFile = [];

        foreach ($rows as $row) {
            // Column 1 is the whole name, stored exactly as it appears in the
            // sheet ("Last, First"). Never split it — the comma is part of the
            // name, not a delimiter.
            $name = $this->cell($row, 0);
            $teamNumber = $this->cell($row, 1);
            $team = $this->teamName($row);

            if ($name === '' || $this->looksLikeHeader($name)) {
                continue;
            }

            $pair = $this->pairKey($name, $teamNumber);
            if (isset($seenInFile[$pair])) {   // same person twice in one file
                $skipped++;
                continue;
            }
            $seenInFile[$pair] = true;

            $existing = $byPair->get($pair) ?: $this->uniqueByName($byName, $name, $matched);

            if ($existing) {
                $existing->fill([
                    'name' => $name,
                    'team_number' => $teamNumber !== '' ? $teamNumber : $existing->team_number,
                    'team' => $team !== '' ? $team : ($existing->team ?: '—'),
                    'active' => true,
                ])->save();

                $matched[$existing->id] = true;
                $touched[] = $this->payload($existing);
                $updated++;

                continue;
            }

            $player = Player::create([
                'id' => (string) Str::uuid(),
                'season_id' => $season->id,
                'name' => $name,
                'team_number' => $teamNumber !== '' ? $teamNumber : null,
                'team' => $team !== '' ? $team : '—',
                'active' => true,
            ]);

            $matched[$player->id] = true;
            $touched[] = $this->payload($player);
            $imported++;
        }

        // Anyone on the season roster the file didn't mention: deactivate,
        // never delete — their entries (and the pot history) stay intact.
        $deactivated = 0;
        foreach ($seasonPlayers as $p) {
            if (! isset($matched[$p->id]) && $p->active) {
                $p->update(['active' => false]);
                $touched[] = $this->payload($p);
                $deactivated++;
            }
        }

        return response()->json([
            'imported' => $imported,
            'updated' => $updated,
            'deactivated' => $deactivated,
            'skipped' => $skipped,
            'players' => $touched,
        ]);
    }

    /**
     * A season player whose name matches and that hasn't already been claimed
     * by another row this import — but only if the name is unambiguous.
     */
    private function uniqueByName($byName, string $name, array $matched): ?Player
    {
        $candidates = ($byName->get($this->nameKey($name)) ?? collect())
            ->reject(fn ($p) => isset($matched[$p->id]));

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    private function payload(Player $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'team_number' => $p->team_number,
            'team' => $p->team ?? '—',
            'active' => (bool) $p->active,
        ];
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

    private function pairKey(?string $name, ?string $teamNumber): string
    {
        return $this->nameKey($name)."\0".mb_strtolower(trim((string) $teamNumber));
    }

    private function nameKey(?string $name): string
    {
        return mb_strtolower(trim((string) $name));
    }

    private function looksLikeHeader(string $name): bool
    {
        return (bool) preg_match('/^(name|player|bowler|last,?\s*first)$/i', trim($name));
    }
}
