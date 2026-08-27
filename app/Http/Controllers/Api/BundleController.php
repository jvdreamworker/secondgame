<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Entry;
use App\Models\Season;
use App\Services\PoolCalculator;
use Illuminate\Http\JsonResponse;

class BundleController extends Controller
{
    /**
     * GET /api/seasons/{season}/bundle
     *
     * One shot of everything the offline frontend needs to seed IndexedDB.
     * Shapes and key names (camelCase config, `week` not `week_number`,
     * entry ids as "{player_id}:{week}") match what pool-app.js / api-sync.js
     * read back — do not "tidy" them without changing the frontend too.
     */
    public function bundle(Season $season): JsonResponse
    {
        $players = $season->players()
            ->orderBy('name')
            ->get(['id', 'name', 'team_number', 'team', 'active'])
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'team_number' => $p->team_number,
                'team' => $p->team ?? '—',
                'active' => (bool) $p->active,
            ])
            ->all();

        $entries = Entry::query()
            ->whereHas('player', fn ($q) => $q->where('season_id', $season->id))
            ->get()
            ->map(fn (Entry $e) => $this->entryPayload($e))
            ->all();

        $results = $season->weeklyResults()
            ->orderBy('week_number')
            ->get()
            ->map(fn ($r) => [
                'week' => $r->week_number,
                'score' => $r->score ?? '',
                'winner_player_id' => $r->winner_player_id,
                'payout' => (float) $r->payout,
                'note' => $r->note ?? '',
            ])
            ->all();

        return response()->json([
            'config' => $this->configPayload($season),
            'players' => $players,
            'entries' => $entries,
            'results' => $results,
        ]);
    }

    /**
     * GET /api/seasons/{season}/stats
     *
     * Not consumed by the frontend (it computes its own), but handy for
     * verifying the two implementations agree.
     */
    public function stats(Season $season): JsonResponse
    {
        $calc = new PoolCalculator($season);

        return response()->json([
            'config' => $this->configPayload($season),
            'weeks' => $calc->weeks(),
            'stats' => array_values($calc->stats()),
        ]);
    }

    private function configPayload(Season $season): array
    {
        return [
            'seasonLabel' => $season->label,
            'entryFee' => (float) $season->entry_fee,
            'startWeek' => (int) $season->start_week,
            'totalWeeks' => (int) $season->total_weeks,
        ];
    }

    private function entryPayload(Entry $e): array
    {
        return [
            'id' => $e->player_id.':'.$e->week_number,
            'player_id' => $e->player_id,
            'week' => (int) $e->week_number,
            'amount' => is_null($e->amount) ? null : (float) $e->amount,
            'status' => $e->status,
            'note' => $e->note ?? '',
        ];
    }
}
