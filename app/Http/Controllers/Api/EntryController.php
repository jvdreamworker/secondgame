<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Entry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class EntryController extends Controller
{
    /**
     * PUT /api/entries
     *
     * Upsert by the unique (player_id, week) pair. Body uses `week`
     * (the frontend's key), which maps to the `week_number` column.
     */
    public function upsert(Request $request): JsonResponse
    {
        $data = $request->validate([
            'player_id' => ['required', 'uuid', 'exists:players,id'],
            'week' => ['required', 'integer', 'min:0'],
            'amount' => ['nullable', 'numeric'],
            'status' => ['required', 'in:paid,covered,exempt'],
            'note' => ['nullable', 'string'],
            'received_on' => ['nullable', 'date'],
        ]);

        $entry = Entry::updateOrCreate(
            ['player_id' => $data['player_id'], 'week_number' => $data['week']],
            [
                'amount' => $data['amount'] ?? null,
                'status' => $data['status'],
                'note' => $data['note'] ?? '',
                'received_on' => $data['received_on'] ?? null,
            ],
        );

        return response()->json([
            'id' => $entry->player_id.':'.$entry->week_number,
            'player_id' => $entry->player_id,
            'week' => (int) $entry->week_number,
            'amount' => is_null($entry->amount) ? null : (float) $entry->amount,
            'status' => $entry->status,
            'note' => $entry->note ?? '',
            'received_on' => $entry->received_on?->format('Y-m-d'),
        ]);
    }

    /**
     * DELETE /api/entries/{player}/{week}
     *
     * 204 whether or not a row existed, so the sync queue can retry freely.
     */
    public function destroy(string $player, int $week): Response
    {
        Entry::query()
            ->where('player_id', $player)
            ->where('week_number', $week)
            ->delete();

        return response()->noContent();
    }
}
