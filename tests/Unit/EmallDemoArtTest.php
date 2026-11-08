<?php

namespace Tests\Unit;

use Database\Seeders\EmallDemoArt;
use Database\Seeders\EmallDemoCatalogue;
use PHPUnit\Framework\TestCase;

/**
 * D-170: the demo pictures depict what the listing names. Production sellers upload real photographs; these drawings only have to be honest.
 */
class EmallDemoArtTest extends TestCase
{
    /** @return list<array{0: string, 1: int}> */
    private function products(): array
    {
        $rows = [];
        foreach (EmallDemoCatalogue::products() as $list) {
            foreach ($list as $row) {
                $rows[] = [$row[0], $row[6]];
            }
        }

        return $rows;
    }

    public function test_every_demo_product_is_drawn_as_its_own_kind_of_thing(): void
    {
        $this->assertGreaterThan(100, count($this->products()));

        foreach ($this->products() as [$name]) {
            $kind = EmallDemoArt::kindFor($name);
            $this->assertNotSame('', $kind, "{$name} has no drawing and would fall back to a plain parcel");
            $this->assertTrue(method_exists(EmallDemoArt::class, 'k_'.$kind), "{$name} maps to '{$kind}' which has no drawer");
        }
    }

    public function test_the_products_the_jury_saw_drawn_wrongly_now_map_to_the_right_object(): void
    {
        $expect = [
            'Lenovo IdeaPad Slim 3, 15.6 inch' => 'laptop',
            'Dell Inspiron 15 Laptop' => 'laptop',
            'Apple MacBook Air 13 inch M2' => 'laptop',
            'Samsung 55 Inch Crystal UHD Smart TV' => 'tv',
            'LG 55 Inch UHD Smart TV' => 'tv',
            'Hisense 50 Inch 4K Smart TV' => 'tv',
            'LED Headlamp 400 Lumen' => 'headlamp',
            'Portable Bluetooth Speaker' => 'speaker',
            'Two-Slice Toaster, Stainless' => 'toaster',
            'Braai Tongs, Stainless Steel' => 'tongs',
            'Cordless Glass Kettle 1.7L' => 'kettle',
            'Alpine 2-Person Dome Tent' => 'tent',
            'Cotton Duvet Set' => 'duvet',
            'Insulated Steel Bottle 750ml' => 'flask',
            'Samsung Galaxy A15 Smartphone' => 'phone',
            'Clear Phone Case' => 'phonecase',
            'Aluminium Laptop Stand' => 'stand',
            'Samsung Galaxy Tab A9 Tablet' => 'tablet',
            'Lenovo Tab M10, 10.1 inch' => 'tablet',
            'Samsung 27 Inch Curved Gaming Monitor' => 'monitor',
            'Monitor Arm, Single' => 'monitorarm',
        ];

        foreach ($expect as $name => $kind) {
            $this->assertSame($kind, EmallDemoArt::kindFor($name), $name);
        }
    }

    public function test_a_picture_is_a_real_800_pixel_jpeg_and_the_same_every_time(): void
    {
        $a = EmallDemoArt::render('Dell Inspiron 15 Laptop', 220, 0);
        $b = EmallDemoArt::render('Dell Inspiron 15 Laptop', 220, 0);

        $this->assertSame($a, $b, 'the seed must be deterministic');
        $info = getimagesizefromstring($a);
        $this->assertSame([800, 800], [$info[0], $info[1]]);
        $this->assertSame('image/jpeg', $info['mime']);
    }

    public function test_the_three_frames_of_one_product_differ(): void
    {
        $frames = array_map(fn (int $f) => md5(EmallDemoArt::render('Cast Iron Skillet 26cm', 8, $f)), [0, 1, 2]);

        $this->assertCount(3, array_unique($frames), 'a gallery needs three different frames');
    }

    public function test_rows_of_products_are_told_apart_by_their_pictures(): void
    {
        $hashes = [];
        foreach ($this->products() as [$name, $hue]) {
            $hashes[md5(EmallDemoArt::render($name, $hue, 0))] = $name;
        }

        $this->assertCount(count($this->products()), $hashes, 'no two listings may share the same picture');
        $kinds = array_unique(array_map(fn ($p) => EmallDemoArt::kindFor($p[0]), $this->products()));
        $this->assertGreaterThan(60, count($kinds), 'the shelves are drawn with many silhouettes, not a handful of repeated glyphs');
    }
}
