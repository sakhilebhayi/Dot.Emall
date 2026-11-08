<?php

namespace App\Models;

use Database\Factories\WishlistFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A person's saved list of products. Private by default; a public link exists only after the owner turns sharing on
 * (share_token is generated then and cleared when sharing is turned off, so an old link stops working).
 */
class Wishlist extends Model
{
    /** @use HasFactory<WishlistFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'name'];

    protected $casts = ['is_shared' => 'boolean'];

    protected $hidden = ['share_token'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(WishlistItem::class)->orderByDesc('id');
    }
}
