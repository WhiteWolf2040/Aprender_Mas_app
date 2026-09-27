<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subject extends Model
{
    protected $fillable = ['created_by', 'name', 'description', 'icon'];

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }
}
