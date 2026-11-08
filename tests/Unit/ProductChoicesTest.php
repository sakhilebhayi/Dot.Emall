<?php

namespace Tests\Unit;

use App\Models\ProductVariant;
use App\Services\Storefront\ProductChoices;
use PHPUnit\Framework\TestCase;

/** The choice logic of the product page, with no database: which variant, what is left to choose, what is crossed out. */
class ProductChoicesTest extends TestCase
{
    private function variants(): \Illuminate\Support\Collection
    {
        $make = fn (int $id, array $options, int $stock) => (new ProductVariant)->forceFill(['id' => $id, 'options' => $options, 'stock' => $stock, 'price' => '10.00', 'is_active' => true]);

        return collect([
            $make(1, ['Size' => 'S', 'Colour' => 'Red'], 3),
            $make(2, ['Size' => 'M', 'Colour' => 'Red'], 5),
            $make(3, ['Size' => 'L', 'Colour' => 'Red'], 0),
            $make(4, ['Size' => 'S', 'Colour' => 'Blue'], 7),
        ]);
    }

    private function order(): array
    {
        return ['Size' => ['S', 'M', 'L'], 'Colour' => ['Red', 'Blue']];
    }

    public function test_nothing_chosen_means_no_variant_and_both_options_missing(): void
    {
        $c = new ProductChoices($this->variants(), $this->order());

        $this->assertNull($c->variant());
        $this->assertSame(['Size', 'Colour'], $c->missing());
        $this->assertFalse($c->complete());
        $this->assertCount(4, $c->matching());
    }

    public function test_a_complete_choice_points_at_one_variant(): void
    {
        $c = new ProductChoices($this->variants(), $this->order(), ['Size' => 'M', 'Colour' => 'Red']);

        $this->assertSame(2, $c->variant()?->id);
        $this->assertSame([], $c->missing());
        $this->assertFalse($c->impossible);
    }

    public function test_a_combination_that_is_not_made_is_impossible_not_guessed(): void
    {
        $c = new ProductChoices($this->variants(), $this->order(), ['Size' => 'M', 'Colour' => 'Blue']);

        $this->assertNull($c->variant());
        $this->assertTrue($c->impossible);
    }

    public function test_values_that_do_not_exist_are_ignored(): void
    {
        $c = new ProductChoices($this->variants(), $this->order(), ['Size' => 'XXL', 'Colour' => 'Red']);

        $this->assertSame(['Size'], $c->missing());
        $this->assertSame('Red', $c->selected['Colour']);
    }

    public function test_the_other_choices_cross_out_what_cannot_go_with_them_and_mark_sold_out_ones_selectable(): void
    {
        $c = new ProductChoices($this->variants(), $this->order(), ['Colour' => 'Blue']);
        $size = collect($c->pickers())->firstWhere('name', 'Size')['options'];
        $by = collect($size)->keyBy('value');

        $this->assertFalse($by['S']['disabled'], 'S exists in Blue');
        $this->assertTrue($by['M']['disabled'], 'M is not made in Blue');
        $this->assertTrue($by['L']['disabled']);

        $red = new ProductChoices($this->variants(), $this->order(), ['Colour' => 'Red']);
        $bySize = collect(collect($red->pickers())->firstWhere('name', 'Size')['options'])->keyBy('value');

        $this->assertFalse($bySize['L']['disabled'], 'Large exists in Red');
        $this->assertTrue($bySize['L']['soldout'], 'but it has no stock: still selectable, so the shopper can ask to be told');
        $this->assertSame('Sold out', $bySize['L']['note']);
        $this->assertFalse($bySize['M']['soldout']);
    }

    public function test_a_variant_id_in_the_link_selects_its_options(): void
    {
        $c = new ProductChoices($this->variants(), $this->order(), [], 4);

        $this->assertSame(4, $c->variant()?->id);
        $this->assertSame(['Size' => 'S', 'Colour' => 'Blue'], $c->selected);
    }

    public function test_a_product_with_one_buyable_variant_has_nothing_to_choose(): void
    {
        $c = new ProductChoices($this->variants()->take(1), $this->order());

        $this->assertSame(1, $c->variant()?->id);
        $this->assertSame([], $c->missing());
    }

    public function test_a_product_without_options_returns_its_only_variant(): void
    {
        $plain = collect([(new ProductVariant)->forceFill(['id' => 9, 'options' => [], 'stock' => 4, 'price' => '5.00'])]);
        $c = new ProductChoices($plain);

        $this->assertFalse($c->hasOptions());
        $this->assertSame(9, $c->variant()?->id);
    }

    public function test_values_follow_the_sellers_order_not_the_order_variants_were_saved(): void
    {
        $c = new ProductChoices($this->variants()->reverse()->values(), ['Size' => ['S', 'M', 'L']]);

        $this->assertSame(['S', 'M', 'L'], $c->axes[0]['values']);
    }
}
