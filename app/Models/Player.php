<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Player extends Model
{
    /**
     * HasUuids generates a UUID on create only when the key is empty, so the
     * client-supplied `id` is used verbatim when present.
     */
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'season_id',
        'name',
        'team_number',
        'team',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(Entry::class);
    }
}
