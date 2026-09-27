<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChildProfile extends Model
{
    protected $fillable = [
        'parent_id',
        'name',
        'age',
        'avatar',
        'progress_data',
        'total_stars',
        'streak',
        'level',
        'equipped_sticker',
        'equipped_costume',
        'equipped_accessories',
        'purchased_items',
        'last_activity_date',
        'energy',
        'energy_reset_at',
    ];

    protected function casts(): array
    {
        return [
            'age' => 'integer',
            'progress_data' => 'array',
            'equipped_accessories' => 'array',
            'purchased_items' => 'array',
            'total_stars' => 'integer',
            'streak' => 'integer',
            'level' => 'integer',
            'last_activity_date' => 'date',
            'energy' => 'integer',
            'energy_reset_at' => 'datetime',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'parent_id');
    }

    public function progress(): HasMany
    {
        return $this->hasMany(Progress::class);
    }

    public function hasActivePlan(): bool
    {
        return $this->parent?->hasActivePlan() ?? false;
    }

    public function hasUnlimitedEnergy(): bool
    {
        return $this->hasActivePlan();
    }

    public function consumeEnergy(): bool
    {
        if ($this->hasUnlimitedEnergy()) {
            return true;
        }

        if (!$this->energy_reset_at || $this->energy_reset_at->isPast()) {
            $this->energy = 3;
            $this->energy_reset_at = now()->addDay();
            $this->save();
        }

        if ($this->energy <= 0) {
            return false;
        }

        $this->decrement('energy');

        return true;
    }
}
