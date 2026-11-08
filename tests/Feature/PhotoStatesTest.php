<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/** D-171: a listing without a photograph says so; a photograph reserves its box and loads early where the shopper reaches for it. */
class PhotoStatesTest extends TestCase
{
    public function test_a_product_card_without_a_photo_says_so_and_leads_with_the_name(): void
    {
        $html = Blade::render('<x-ds.product-card href="/p/1" title="Charcoal Soap Bar" amount="59.00" ratio="4 / 5" />');

        $this->assertStringContainsString('Seller has not added a photo', $html);
        $this->assertStringContainsString('ds-pcard__noimg-name', $html);
        $this->assertStringNotContainsString('<img', $html, 'no stand-in picture of something else');
    }

    public function test_a_product_card_with_a_photo_reserves_its_box_from_the_ratio_and_loads_eagerly_only_when_told(): void
    {
        $lazy = Blade::render('<x-ds.product-card href="/p/1" title="Kettle" image="/k.jpg" amount="449.00" ratio="1 / 1" />');
        $this->assertStringNotContainsString('Seller has not added a photo', $lazy);
        $this->assertStringContainsString('width="480" height="480"', $lazy);
        $this->assertStringContainsString('loading="lazy"', $lazy);

        $tall = Blade::render('<x-ds.product-card href="/p/1" title="Kettle" image="/k.jpg" amount="449.00" :eager="true" />');
        $this->assertStringContainsString('width="480" height="600"', $tall);
        $this->assertStringContainsString('loading="eager"', $tall);
        $this->assertStringContainsString('fetchpriority="high"', $tall);
    }

    public function test_a_gallery_without_photos_says_so(): void
    {
        $html = Blade::render('<x-ds.product-gallery :images="[]" />');

        $this->assertStringContainsString('Seller has not added a photo', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('ds-gallery__thumbs', $html);
        $this->assertStringNotContainsString('data-gallery="zoom"', $html, 'nothing to zoom');
    }

    public function test_every_gallery_picture_and_thumbnail_loads_eagerly_so_no_slot_is_an_empty_square(): void
    {
        $images = [['src' => '/a.jpg', 'alt' => 'A'], ['src' => '/b.jpg', 'alt' => 'B'], ['src' => '/c.jpg', 'alt' => 'C']];
        $html = Blade::render('<x-ds.product-gallery :images="$images" />', ['images' => $images]);

        $this->assertSame(6, substr_count($html, 'loading="eager"'), 'three pictures and three thumbnails');
        $this->assertStringNotContainsString('loading="lazy"', $html);
        $this->assertSame(3, substr_count($html, 'width="68" height="68"'), 'each thumbnail reserves its size');
        $this->assertStringContainsString('fetchpriority="high"', $html);
    }

    public function test_a_cart_line_without_a_photo_says_so(): void
    {
        $html = Blade::render('<x-ds.cart-line title="Charcoal Soap Bar" :quantity="1" :line-cents="5900" />');

        $this->assertStringContainsString('No photo', $html);
        $this->assertStringNotContainsString('<img', $html);
    }
}
