<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A node in the category tree. `path` is the materialised ancestry ("/1/5/9/", ends with the node's own id) so a
 * whole subtree is one indexed prefix match; depth is 0 for a root. App\Services\Catalogue\CategoryTree owns
 * moving nodes and the rollup counts.
 */
class StoreCategory extends Model
{
    protected $table = 'store_categories';

    protected $fillable = ['name', 'slug', 'parent_id', 'sort_order', 'description', 'image_path', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'sort_order' => 'integer', 'depth' => 'integer'];

    protected static function booted(): void
    {
        static::creating(function (StoreCategory $category): void {
            $category->slug = $category->slug ?: Str::slug($category->name);
        });

        // The path needs the id, so it is written right after the insert (and rewritten, with every descendant's,
        // by CategoryTree::move() when a node changes parent).
        static::created(function (StoreCategory $category): void {
            $parent = $category->parent_id ? static::query()->find($category->parent_id) : null;

            $category->forceFill([
                'path' => ($parent?->path ?? '/').$category->id.'/',
                'depth' => $parent ? $parent->depth + 1 : 0,
            ])->saveQuietly();
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(StoreCategory::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(StoreCategory::class, 'parent_id')->orderBy('sort_order')->orderBy('name');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'category_id');
    }

    public function attributeDefinitions(): HasMany
    {
        return $this->hasMany(CategoryAttribute::class, 'category_id')->orderBy('position')->orderBy('id');
    }

    /** This category and everything beneath it. */
    public function scopeWithDescendantsOf(Builder $query, StoreCategory $root): void
    {
        $query->where('path', 'like', $root->path.'%');
    }

    /** @return array<int, int> ids of this category and all descendants */
    public function subtreeIds(): array
    {
        return static::query()->where('path', 'like', ($this->path ?? '/'.$this->id.'/').'%')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
