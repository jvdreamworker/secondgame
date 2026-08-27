<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Entry extends Model
{
    protected $fillable = [
        'player_id',
        'week_number',
        'amount',
        'status',
        'note',
    ];

    protected $casts = [
        'amount' => 'float',
        'week_number' => 'integer',
    ];

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
