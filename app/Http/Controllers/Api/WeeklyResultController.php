<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Season;
use App\Models\WeeklyResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WeeklyResultController extends Controller
{
    /**
     * PUT /api/weekly-results/{week}
     *
     * Upsert the result for a week on the current season. Body:
     * { score, winner_player_id, payout, note }. `score` is a free string
     * so "187?" is allowed.
     */
    public function upsert(Request $request, int $week): JsonResponse
    {
        $data = $request->validate([
            'score' => ['nullable', 'string', 'max:255'],
            'winner_player_id' => ['nullable', 'uuid', 'exists:players,id'],
            'payout' => ['nullable', 'numeric'],
            'note' => ['nullable', 'string'],
        ]);

        $season = Season::current();
        abort_if($season === null, 409, 'No season configured.');

        $result = WeeklyResult::updateOrCreate(
            ['season_id' => $season->id, 'week_number' => $week],
            [
                'score' => $data['score'] ?? null,
                'winner_player_id' => $data['winner_player_id'] ?? null,
                'payout' => $data['payout'] ?? 0,
                'note' => $data['note'] ?? '',
            ],
        );

        return response()->json([
            'week' => (int) $result->week_number,
            'score' => $result->score ?? '',
            'winner_player_id' => $result->winner_player_id,
            'payout' => (float) $result->payout,
            'note' => $result->note ?? '',
        ]);
    }
}
