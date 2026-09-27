<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Progress extends Model
{
    protected $table = 'progresses';
    protected $fillable = [
        'child_profile_id', 'activity_id', 'subject', 'score', 'total',
        'elapsed_seconds', 'completed_at',
    ];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(ChildProfile::class, 'child_profile_id');
    }

    public function child(): BelongsTo
    {
        return $this->belongsTo(ChildProfile::class, 'child_profile_id');
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }
}
