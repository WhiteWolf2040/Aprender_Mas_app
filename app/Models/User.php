<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'role',
        'email',
        'avatar',
        'total_stars',
        'streak',
        'level',
        'equipped_sticker',
        'equipped_costume',
        'last_activity_date',
        'password',
        'plan_key',
        'energy',
        'energy_reset_at',
        'stripe_customer_id',
        'stripe_subscription_id',
        'subscription_status',
        'subscription_ends_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'last_activity_date' => 'date',
            'level' => 'integer',
            'total_stars' => 'integer',
            'streak' => 'integer',
            'energy' => 'integer',
            'energy_reset_at' => 'datetime',
            'subscription_ends_at' => 'datetime',
        ];
    }

    public function children(): HasMany
    {
        return $this->hasMany(ChildProfile::class, 'parent_id');
    }

    public function hasActivePlan(): bool
    {
        if ($this->role === 'padre'
            && $this->plan_key !== 'free'
            && in_array($this->subscription_status, ['active', 'trialing'], true)
            && ($this->subscription_ends_at === null || $this->subscription_ends_at->isFuture())) {
            return true;
        }

        return false;
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
