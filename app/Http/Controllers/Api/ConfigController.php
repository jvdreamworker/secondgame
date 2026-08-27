<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Season;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConfigController extends Controller
{
    /**
     * PATCH /api/config
     *
     * Updates the current season's settings. Body keys are the frontend's
     * camelCase names; they map onto season columns.
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'seasonLabel' => ['sometimes', 'string', 'max:255'],
            'entryFee' => ['sometimes', 'numeric', 'min:0'],
            'startWeek' => ['sometimes', 'integer', 'min:0'],
            'totalWeeks' => ['sometimes', 'integer', 'min:1', 'max:104'],
        ]);

        $season = Season::current();
        abort_if($season === null, 409, 'No season configured.');

        $map = [
            'seasonLabel' => 'label',
            'entryFee' => 'entry_fee',
            'startWeek' => 'start_week',
            'totalWeeks' => 'total_weeks',
        ];

        foreach ($map as $input => $column) {
            if (array_key_exists($input, $data)) {
                $season->{$column} = $data[$input];
            }
        }

        $season->save();

        return response()->json([
            'seasonLabel' => $season->label,
            'entryFee' => (float) $season->entry_fee,
            'startWeek' => (int) $season->start_week,
            'totalWeeks' => (int) $season->total_weeks,
        ]);
    }
}
