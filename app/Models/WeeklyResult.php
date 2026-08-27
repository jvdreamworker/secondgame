<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeeklyResult extends Model
{
    protected $fillable = [
        'season_id',
        'week_number',
        'score',
        'winner_player_id',
        'payout',
        'note',
    ];

    protected $casts = [
        'payout' => 'float',
        'week_number' => 'integer',
    ];

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function winner(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'winner_player_id');
    }
}
