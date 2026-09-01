<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Player;
use App\Models\Season;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlayerController extends Controller
{
    /**
     * POST /api/players
     *
     * Body: { id (client UUID), name, team, active }
     * Idempotent on `id` so the sync queue can safely retry.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:255'],
            'team_number' => ['nullable', 'string', 'max:255'],
            'team' => ['nullable', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $season = Season::current();
        abort_if($season === null, 409, 'No season configured.');

        $player = Player::updateOrCreate(
            ['id' => $data['id']],
            [
                'season_id' => $season->id,
                'name' => $data['name'],
                'team_number' => $data['team_number'] ?? null,
                'team' => $data['team'] ?? '—',
                'active' => $data['active'] ?? true,
            ],
        );

        return response()->json($this->payload($player), 201);
    }

    /**
     * PATCH /api/players/{player}
     *
     * Partial update — any of name / team / active.
     */
    public function update(Request $request, Player $player): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'team_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'team' => ['sometimes', 'nullable', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $player->fill($data)->save();

        return response()->json($this->payload($player));
    }

    /**
     * PATCH /api/players/team/{teamNumber}/name  { team: "New Name" }
     *
     * Bulk-set the team NAME on every player in the current season that has
     * this team number. Used by the roster edit modal's "update the whole
     * team too?" prompt.
     */
    public function renameTeam(Request $request, string $teamNumber): JsonResponse
    {
        $data = $request->validate([
            'team' => ['required', 'string', 'max:255'],
        ]);

        $season = Season::current();
        abort_if($season === null, 409, 'No season configured.');

        $players = $season->players()->where('team_number', $teamNumber)->get();
        foreach ($players as $player) {
            $player->update(['team' => $data['team']]);
        }

        return response()->json([
            'updated' => $players->count(),
            'players' => $players->map(fn (Player $p) => $this->payload($p))->all(),
        ]);
    }

    private function payload(Player $player): array
    {
        return [
            'id' => $player->id,
            'name' => $player->name,
            'team_number' => $player->team_number,
            'team' => $player->team ?? '—',
            'active' => (bool) $player->active,
        ];
    }
}
