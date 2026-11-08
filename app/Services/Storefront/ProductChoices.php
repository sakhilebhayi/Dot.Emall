<?php

namespace App\Services\Storefront;

use App\Models\ProductVariant;
use Illuminate\Support\Collection;

/**
 * What a shopper has chosen so far on a product with options (Size, Colour), and what is left to choose.
 *
 * It is pure: give it the product's active variants and the choices asked for, and it says which variant that is (or that
 * the choice is incomplete or impossible), which option values are still on offer given the other choices, and how many
 * are in stock. The page asks it on every request, so the server is always the one that decides what can be bought.
 */
final class ProductChoices
{
    /** @var list<array{name: string, values: list<string>}> */
    public array $axes = [];

    /** @var array<string, string> option name => chosen value */
    public array $selected = [];

    public bool $impossible = false;

    /**
     * @param  Collection<int, ProductVariant>  $variants  the ACTIVE variants only
     * @param  array<string, list<string>>  $optionOrder  the seller's own order: option name => its values (ProductOption rows)
     * @param  array<string, mixed>  $requested  option name => value, from the query string
     */
    public function __construct(public readonly Collection $variants, array $optionOrder = [], array $requested = [], ?int $variantId = null)
    {
        $this->axes = $this->axesFrom($optionOrder);

        $names = array_column($this->axes, 'name');

        if ($variantId !== null && ($pinned = $variants->firstWhere('id', $variantId)) !== null) {
            foreach ($names as $name) {
                if (isset($pinned->options[$name])) {
                    $this->selected[$name] = (string) $pinned->options[$name];
                }
            }
        } else {
            foreach ($this->axes as $axis) {
                $given = $requested[$axis['name']] ?? null;

                if (is_string($given) && in_array($given, $axis['values'], true)) {
                    $this->selected[$axis['name']] = $given;
                }
            }
        }

        // A product that has exactly one buyable variant has nothing to choose.
        if ($variants->count() === 1 && $names !== []) {
            $only = $variants->first();

            foreach ($names as $name) {
                if (isset($only->options[$name])) {
                    $this->selected[$name] = (string) $only->options[$name];
                }
            }
        }

        $this->impossible = $this->complete() && $this->matching()->isEmpty();
    }

    public function hasOptions(): bool
    {
        return $this->axes !== [];
    }

    public function complete(): bool
    {
        return count($this->selected) === count($this->axes);
    }

    /** @return list<string> option names not chosen yet */
    public function missing(): array
    {
        return array_values(array_diff(array_column($this->axes, 'name'), array_keys($this->selected)));
    }

    /** The variants that fit what has been chosen so far. @return Collection<int, ProductVariant> */
    public function matching(): Collection
    {
        return $this->fitting($this->selected);
    }

    /** The one variant the choices point at, or null while they are incomplete (or impossible). */
    public function variant(): ?ProductVariant
    {
        if (! $this->hasOptions()) {
            return $this->variants->first();
        }

        return $this->complete() ? $this->matching()->first() : null;
    }

    /**
     * The picker for each option: every value the product comes in. A value is `disabled` when no variant exists with it together
     * with the OTHER choices already made, and `soldout` when one exists but has no stock (still selectable, so a shopper can ask to
     * be told when it is back).
     *
     * @return list<array{name: string, selected: ?string, options: list<array{value: string, label: string, disabled: bool, soldout: bool, note: ?string}>}>
     */
    public function pickers(): array
    {
        $pickers = [];

        foreach ($this->axes as $axis) {
            $others = array_diff_key($this->selected, [$axis['name'] => true]);
            $options = [];

            foreach ($axis['values'] as $value) {
                $fits = $this->fitting($others + [$axis['name'] => $value]);
                $inStock = $fits->contains(fn (ProductVariant $v) => $v->stock > 0);

                $options[] = [
                    'value' => $value,
                    'label' => $value,
                    'disabled' => $fits->isEmpty(),
                    'soldout' => $fits->isNotEmpty() && ! $inStock,
                    'note' => $fits->isEmpty() ? 'Not available with your other choices' : ($inStock ? null : 'Sold out'),
                ];
            }

            $pickers[] = ['name' => $axis['name'], 'selected' => $this->selected[$axis['name']] ?? null, 'options' => $options];
        }

        return $pickers;
    }

    /**
     * @param  array<string, string>  $choice
     * @return Collection<int, ProductVariant>
     */
    private function fitting(array $choice): Collection
    {
        return $this->variants->filter(function (ProductVariant $v) use ($choice) {
            foreach ($choice as $name => $value) {
                if (mb_strtolower(trim((string) ($v->options[$name] ?? ''))) !== mb_strtolower(trim($value))) {
                    return false;
                }
            }

            return true;
        })->values();
    }

    /**
     * @param  array<string, list<string>>  $order
     * @return list<array{name: string, values: list<string>}>
     */
    private function axesFrom(array $order): array
    {
        $values = [];

        foreach ($this->variants as $variant) {
            foreach ((array) $variant->options as $name => $value) {
                $values[(string) $name][(string) $value] = true;
            }
        }

        if ($values === []) {
            return [];
        }

        $names = array_values(array_unique(array_merge(array_intersect(array_keys($order), array_keys($values)), array_keys($values))));

        return array_map(function (string $name) use ($values, $order) {
            $found = array_map('strval', array_keys($values[$name]));
            $sequence = array_map('strval', $order[$name] ?? []);
            usort($found, fn (string $a, string $b) => (array_search($a, $sequence, true) === false ? PHP_INT_MAX : array_search($a, $sequence, true)) <=> (array_search($b, $sequence, true) === false ? PHP_INT_MAX : array_search($b, $sequence, true)));

            return ['name' => $name, 'values' => $found];
        }, $names);
    }
}
