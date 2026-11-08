<?php

namespace Tests\Feature\Discovery;

use App\Services\Search\ParsedQuery;
use App\Services\Search\QueryUnderstanding;
use App\Services\Search\Text;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsCatalogue;
use Tests\TestCase;

class QueryUnderstandingTest extends TestCase
{
    use BuildsCatalogue;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCatalogue();

        $store = $this->store();
        $tvs = $this->category('Televisions');
        $laptops = $this->category('Laptops');
        $phones = $this->category('Cell Phones');
        $tv = $this->listing($store, 'Samsung 55 Inch Smart TV', 11999.0, ['category_id' => $tvs->id], 'Samsung');
        $this->listing($store, 'Lenovo Laptop', 8999.0, ['category_id' => $laptops->id], 'Lenovo');
        $this->listing($store, 'Galaxy Phone', 4999.0, ['category_id' => $phones->id], 'Samsung');
        $this->catalogue->attribute($tv->id, 'screen_size', 'Screen size', '55', 55.0, 'inch');
        $this->catalogue->attribute($tv->id, 'colour', 'Colour', 'Black');
        $this->reindex();
    }

    private function parse(string $q): ParsedQuery
    {
        return app(QueryUnderstanding::class)->parse($q);
    }

    #[DataProvider('prices')]
    public function test_price_phrases(string $query, ?float $min, ?float $max): void
    {
        $parsed = $this->parse($query);

        $this->assertSame($min, $parsed->inferred['price_min'] ?? null);
        $this->assertSame($max, $parsed->inferred['price_max'] ?? null);
    }

    public static function prices(): array
    {
        return [
            'under with R and comma' => ['laptop under R15,000', null, 15000.0],
            'below k suffix' => ['tv below 5k', null, 5000.0],
            'bare number' => ['phone under 3000', null, 3000.0],
            'up to' => ['laptop up to R12 000', null, 12000.0],
            'between' => ['laptop between R2,000 and R5,000', 2000.0, 5000.0],
            'dash range' => ['laptop R2000-R5000', 2000.0, 5000.0],
            'over' => ['phone over R500', 500.0, null],
            'a screen size is not a price' => ['tv under 55 inch', null, null],
            'no price' => ['samsung tv', null, null],
        ];
    }

    public function test_brand_category_and_attribute_are_found_in_a_natural_request(): void
    {
        $parsed = $this->parse('samsung 55 inch tv under R15,000');

        $this->assertSame('Samsung', $parsed->inferred['brand']);
        $this->assertSame('Televisions', $parsed->inferred['category_label']);
        $this->assertSame(['screen_size' => ['55 inch']], $parsed->inferred['attributes']);
        $this->assertSame(15000.0, $parsed->inferred['price_max']);
        $this->assertSame([], $parsed->slots, 'everything was understood, nothing is left to match as words');
    }

    public function test_a_synonym_finds_the_category(): void
    {
        $this->assertSame('Cell Phones', $this->parse('smartphone')->inferred['category_label']);
        $this->assertSame('Laptops', $this->parse('notebook')->inferred['category_label']);
        $this->assertSame('Televisions', $this->parse('telly')->inferred['category_label']);
    }

    public function test_a_number_with_a_unit_is_kept_as_words_when_no_listing_has_that_value(): void
    {
        $parsed = $this->parse('tv 99 inch');

        $this->assertArrayNotHasKey('attributes', $parsed->inferred);
        $this->assertContains(['99'], $parsed->slots);
    }

    public function test_a_colour_is_read_only_when_a_listing_has_it(): void
    {
        $this->assertSame(['colour' => ['black']], $this->parse('black tv')->inferred['attributes']);
        $this->assertArrayNotHasKey('attributes', $this->parse('purple tv')->inferred);
    }

    public function test_in_stock_is_read(): void
    {
        $parsed = $this->parse('laptop in stock');

        $this->assertTrue($parsed->inferred['in_stock']);
        $this->assertSame([], $parsed->slots);
    }

    public function test_synonyms_become_alternatives_of_one_word(): void
    {
        $parsed = app(QueryUnderstanding::class)->literal('takkies');

        $this->assertContains('sneaker', $parsed->slots[0]);
        $this->assertContains('trainer', $parsed->slots[0]);
        $this->assertNotEmpty($parsed->synonymsUsed);
    }

    public function test_text_normalisation(): void
    {
        $this->assertSame('moller cafe', Text::index('Möller Café'));
        $this->assertSame('55 inch tv', Text::index('55" TV'));
        $this->assertSame('battery', Text::index('Batteries'));
        $this->assertSame('glass', Text::index('Glasses'));
        $this->assertSame('256 gb phone', Text::index('256GB phone'));
        $this->assertSame('galaxy s23', Text::index('Galaxy S23'));
        $this->assertSame('usb c cable', Text::index('USB-C cable'));
    }
}
