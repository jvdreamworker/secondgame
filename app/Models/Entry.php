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
        'received_on',
    ];

    protected $casts = [
        'amount' => 'float',
        'week_number' => 'integer',
        'received_on' => 'date:Y-m-d',
    ];

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
