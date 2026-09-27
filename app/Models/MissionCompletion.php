<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MissionCompletion extends Model
{
    protected $fillable = ['child_profile_id', 'mission_key', 'stars'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(ChildProfile::class, 'child_profile_id');
    }
}
