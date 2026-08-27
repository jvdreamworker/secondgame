<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Season extends Model
{
    protected $fillable = [
        'label',
        'entry_fee',
        'start_week',
        'total_weeks',
    ];

    protected $casts = [
        'entry_fee' => 'float',
        'start_week' => 'integer',
        'total_weeks' => 'integer',
    ];

    public function players(): HasMany
    {
        return $this->hasMany(Player::class);
    }

    public function weeklyResults(): HasMany
    {
        return $this->hasMany(WeeklyResult::class);
    }

    /**
     * The season every write endpoint operates on. This is a single-operator
     * app with one active season at a time; "current" is simply the newest.
     */
    public static function current(): ?self
    {
        return static::query()->orderByDesc('id')->first();
    }
}
