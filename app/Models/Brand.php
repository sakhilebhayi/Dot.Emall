<?php

namespace App\Models;

use Database\Factories\BrandFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Brand extends Model
{
    /** @use HasFactory<BrandFactory> */
    use HasFactory;

    protected $fillable = ['name', 'slug', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    protected static function booted(): void
    {
        static::saving(function (Brand $brand): void {
            $brand->name = trim($brand->name);
            $brand->slug = $brand->slug ?: Str::slug($brand->name);
        });
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * The brand called this, whatever its capitalisation ("samsung" finds "Samsung"); created on first use.
     */
    public static function named(string $name): self
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
        $slug = Str::slug($name) ?: 'brand';

        return static::query()->firstOrCreate(['slug' => $slug], ['name' => $name]);
    }
}
