<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Jetstream\HasProfilePhoto;
use Laravel\Jetstream\HasTeams;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasProfilePhoto;
    use HasTeams;
    use Notifiable;
    use TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'profile_photo_url',
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
            'is_platform_operator' => 'boolean',
        ];
    }

    /**
     * Stores this user owns as a seller.
     */
    public function stores(): HasMany
    {
        return $this->hasMany(Store::class, 'owner_id');
    }

    /**
     * The user's one store. The marketplace is deliberately one store per
     * user (an owner decision, not a technicality); oldestOfMany() makes
     * the answer deterministic even on a database that predates the
     * one-store-per-owner rule and somehow holds more than one.
     */
    public function store(): HasOne
    {
        return $this->hasOne(Store::class, 'owner_id')->oldestOfMany();
    }

    /**
     * How this user is named to people who are not them (reviews seen by
     * guests and other buyers): first name and the initial of the rest.
     */
    public function publicName(): string
    {
        $parts = preg_split('/\s+/', trim((string) $this->name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($parts) === 0) {
            return 'Buyer';
        }

        if (count($parts) === 1) {
            return $parts[0];
        }

        return $parts[0].' '.mb_strtoupper(mb_substr($parts[count($parts) - 1], 0, 1)).'.';
    }

    /**
     * Orders this user has placed as a buyer.
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * This user's current shopping cart contents.
     */
    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    /**
     * Reviews this user has left on products.
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }
}
