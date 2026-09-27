<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubjectCompletion extends Model
{
    protected $fillable = ['child_profile_id', 'subject'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(ChildProfile::class, 'child_profile_id');
    }
}
