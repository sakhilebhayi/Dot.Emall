<?php

namespace App\Models;

use App\Services\Catalogue\PriceHistory;
use App\Services\Catalogue\VariantSync;
use App\Support\Money;
use Database\Factories\ProductVariantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One buyable thing: a product with its options chosen (Size M, Colour Red), or a product's only, implicit
 * variant when it has no options. Price and stock live here; products.price / stock are kept in step
 * (App\Services\Catalogue\VariantSync) so a simple product reads and writes exactly as it always did.
 *
 * Stock changes go through App\Services\Catalogue\Inventory, never an ad-hoc update, so every change is
 * lock-protected and leaves a StockMovement.
 */
class ProductVariant extends Model
{
    /** @use HasFactory<ProductVariantFactory> */
    use HasFactory;

    protected $fillable = [
        'product_id', 'sku', 'options', 'option_key', 'price', 'stock', 'is_active', 'is_default', 'position', 'image_id',
    ];

    protected $casts = [
        'options' => 'array',
        'price' => 'decimal:2',
        'stock' => 'integer',
        'stock_version' => 'integer',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (ProductVariant $variant): void {
            $variant->option_key = self::keyFor($variant->options ?? []);
        });

        static::created(function (ProductVariant $variant): void {
            app(PriceHistory::class)->record($variant, null, (string) $variant->price);
        });

        static::updated(function (ProductVariant $variant): void {
            if ($variant->wasChanged('price')) {
                app(PriceHistory::class)->record($variant, (string) $variant->getOriginal('price'), (string) $variant->price);
            }

            if (! VariantSync::syncing() && $variant->wasChanged(['price', 'stock', 'is_active', 'sku'])) {
                app(VariantSync::class)->refreshProduct($variant->product_id);
            }
        });

        static::deleted(function (ProductVariant $variant): void {
            if (! VariantSync::syncing()) {
                app(VariantSync::class)->refreshProduct($variant->product_id);
            }
        });
    }

    /**
     * @param  array<string, string>  $options
     */
    public static function keyFor(array $options): string
    {
        if ($options === []) {
            return '';
        }

        ksort($options);

        return mb_substr(collect($options)->map(fn ($value, $name) => mb_strtolower(trim((string) $name)).'='.mb_strtolower(trim((string) $value)))->implode('|'), 0, 255);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(ProductImage::class, 'image_id');
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'variant_id');
    }

    public function priceHistory(): HasMany
    {
        return $this->hasMany(ProductPriceHistory::class, 'variant_id')->orderBy('changed_at')->orderBy('id');
    }

    /** "Size: M, Colour: Red", or null for the implicit variant. */
    public function label(): ?string
    {
        $options = $this->options ?? [];

        return $options === [] ? null : collect($options)->map(fn ($value, $name) => "{$name}: {$value}")->implode(', ');
    }

    public function priceCents(): int
    {
        return Money::toCents($this->price);
    }

    public function isLow(): bool
    {
        return $this->stock <= (int) ($this->product?->low_stock_threshold ?? 5);
    }
}
