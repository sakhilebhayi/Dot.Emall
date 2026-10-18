<?php

namespace Tests\Feature\Marketplace;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsMarketplace;
use Tests\TestCase;

class ProductImageTest extends TestCase
{
    use BuildsMarketplace;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function upload($seller, Product $product, array $files)
    {
        return $this->actingAs($seller)->post(route('seller.products.images.store', $product), ['images' => $files]);
    }

    private function jpegWithGpsMarker(int $w = 800, int $h = 600): string
    {
        $img = imagecreatetruecolor($w, $h);
        ob_start();
        imagejpeg($img);
        $jpeg = ob_get_clean();

        // Splice an APP1/EXIF segment carrying a recognisable "GPS" string in after the SOI marker.
        $payload = "Exif\0\0SECRETGPS-LAT-26.2041-LON-28.0473";
        $segment = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

        return substr($jpeg, 0, 2).$segment.substr($jpeg, 2);
    }

    public function test_a_seller_uploads_photos_and_the_first_becomes_the_cover(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller);

        $this->upload($seller, $product, [
            UploadedFile::fake()->image('front.jpg', 800, 600),
            UploadedFile::fake()->image('side.png', 640, 480),
            UploadedFile::fake()->image('detail.webp', 640, 480),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $images = $product->images()->get();
        $this->assertCount(3, $images);
        $this->assertSame([0, 1, 2], $images->pluck('sort_order')->all());
        $this->assertSame($images->first()->id, $product->images()->first()->id, 'cover = lowest sort_order');

        foreach ($images as $image) {
            Storage::disk('public')->assertExists($image->path);
        }
    }

    public function test_files_are_stored_on_the_public_disk_under_random_names_never_the_uploaded_name(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller);

        $this->upload($seller, $product, [UploadedFile::fake()->image('my-holiday-in-durban-FINAL.jpg', 800, 600)]);

        $path = $product->images()->first()->path;
        $this->assertStringStartsWith('product-images/'.$product->id.'/', $path);
        $this->assertStringNotContainsString('durban', $path);
        $this->assertMatchesRegularExpression('#^product-images/\d+/[A-Za-z0-9]{40}\.(jpg|png|webp)$#', $path);
    }

    public function test_at_most_six_photos_per_listing(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller);
        $make = fn (int $n) => collect(range(1, $n))->map(fn ($i) => UploadedFile::fake()->image("p{$i}.jpg", 400, 400))->all();

        $this->upload($seller, $product, $make(6))->assertSessionHasNoErrors();
        $this->assertSame(6, $product->images()->count());

        $this->upload($seller, $product, $make(1))->assertSessionHasErrors('images');
        $this->assertSame(6, $product->images()->count());
    }

    public function test_seven_files_in_one_request_are_refused_as_a_whole(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller);
        $files = collect(range(1, 7))->map(fn ($i) => UploadedFile::fake()->image("p{$i}.jpg", 400, 400))->all();

        $this->upload($seller, $product, $files)->assertSessionHasErrors('images');

        $this->assertSame(0, $product->images()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_existing_photos_count_towards_the_limit(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller);
        $this->upload($seller, $product, collect(range(1, 4))->map(fn ($i) => UploadedFile::fake()->image("a{$i}.jpg", 400, 400))->all());

        $this->upload($seller, $product, collect(range(1, 3))->map(fn ($i) => UploadedFile::fake()->image("b{$i}.jpg", 400, 400))->all())
            ->assertSessionHasErrors('images');

        $this->assertSame(4, $product->images()->count());
    }

    public function test_only_jpeg_png_and_webp_are_accepted(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller);

        foreach ([
            UploadedFile::fake()->create('doc.pdf', 20, 'application/pdf'),
            UploadedFile::fake()->createWithContent('anim.gif', base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7')),
            UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            UploadedFile::fake()->createWithContent('shell.php', '<?php echo 1;'),
        ] as $file) {
            $this->upload($seller, $product, [$file])->assertSessionHasErrors('images.0');
        }

        $this->assertSame(0, $product->images()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_the_real_content_is_checked_not_the_file_extension(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller);

        $this->upload($seller, $product, [UploadedFile::fake()->createWithContent('innocent.jpg', '<?php system($_GET["c"]); ?>')])
            ->assertSessionHasErrors('images.0');

        $this->assertSame(0, $product->images()->count());
    }

    public function test_a_file_with_an_image_header_that_does_not_decode_is_refused(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller);
        $truncated = UploadedFile::fake()->createWithContent('broken.jpg', "\xFF\xD8\xFF\xE0".str_repeat('x', 300));

        $this->upload($seller, $product, [$truncated])->assertSessionHasErrors('images.0');

        $this->assertSame(0, $product->images()->count());
    }

    public function test_files_over_four_megabytes_are_refused(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller);

        $this->upload($seller, $product, [UploadedFile::fake()->image('big.jpg', 800, 600)->size(4097)])->assertSessionHasErrors('images.0');
        $this->upload($seller, $product, [UploadedFile::fake()->image('ok.jpg', 800, 600)->size(4096)])->assertSessionHasNoErrors();

        $this->assertSame(1, $product->images()->count());
    }

    public function test_absurd_dimensions_are_refused_in_both_directions(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller);

        $this->upload($seller, $product, [UploadedFile::fake()->image('huge.png', 6001, 100)])->assertSessionHasErrors('images.0');
        $this->upload($seller, $product, [UploadedFile::fake()->image('tiny.png', 40, 40)])->assertSessionHasErrors('images.0');

        $this->assertSame(0, $product->images()->count());
    }

    public function test_photos_are_reencoded_so_embedded_gps_metadata_does_not_survive(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller);
        $original = $this->jpegWithGpsMarker();
        $this->assertStringContainsString('SECRETGPS', $original, 'the fixture really carries the marker');

        $this->upload($seller, $product, [UploadedFile::fake()->createWithContent('phone-photo.jpg', $original)])->assertSessionHasNoErrors();

        $stored = Storage::disk('public')->get($product->images()->first()->path);
        $this->assertStringNotContainsString('SECRETGPS', $stored);
        $this->assertStringNotContainsString('Exif', $stored);
        $this->assertNotFalse(getimagesizefromstring($stored), 'and it is still a valid image');
    }

    public function test_a_phone_photo_taken_sideways_is_stored_the_right_way_up(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller);

        // 800x400 landscape pixels carrying EXIF Orientation = 6 (rotate 90 degrees clockwise to view).
        $img = imagecreatetruecolor(800, 400);
        ob_start();
        imagejpeg($img);
        $jpeg = ob_get_clean();
        $tiff = "MM\x00*\x00\x00\x00\x08"."\x00\x01"."\x01\x12\x00\x03\x00\x00\x00\x01\x00\x06\x00\x00"."\x00\x00\x00\x00";
        $payload = "Exif\0\0".$tiff;
        $withExif = substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($payload) + 2).$payload.substr($jpeg, 2);

        $this->upload($seller, $product, [UploadedFile::fake()->createWithContent('sideways.jpg', $withExif)])->assertSessionHasNoErrors();

        [$w, $h] = getimagesizefromstring(Storage::disk('public')->get($product->images()->first()->path));
        $this->assertSame([400, 800], [$w, $h], 'orientation was applied before the tag was discarded');
    }

    public function test_large_photos_are_scaled_down(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller);

        $this->upload($seller, $product, [UploadedFile::fake()->image('big.jpg', 3000, 2000)]);

        [$w, $h] = getimagesizefromstring(Storage::disk('public')->get($product->images()->first()->path));
        $this->assertSame(1600, $w);
        $this->assertSame(1067, $h);
    }

    public function test_small_photos_are_not_enlarged(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller);

        $this->upload($seller, $product, [UploadedFile::fake()->image('small.png', 500, 300)]);

        [$w, $h] = getimagesizefromstring(Storage::disk('public')->get($product->images()->first()->path));
        $this->assertSame([500, 300], [$w, $h]);
    }

    public function test_a_seller_cannot_upload_to_someone_elses_listing(): void
    {
        $owner = $this->makeSeller();
        $intruder = $this->makeSeller();
        $product = $this->makeListing($owner);

        $this->upload($intruder, $product, [UploadedFile::fake()->image('a.jpg', 400, 400)])->assertForbidden();

        $this->assertSame(0, $product->images()->count());
    }

    public function test_deleting_a_photo_removes_the_file_and_promotes_the_next_cover(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller);
        $this->upload($seller, $product, [UploadedFile::fake()->image('a.jpg', 400, 400), UploadedFile::fake()->image('b.jpg', 400, 400), UploadedFile::fake()->image('c.jpg', 400, 400)]);
        [$a, $b, $c] = $product->images()->get()->all();

        $this->actingAs($seller)->delete(route('seller.products.images.destroy', [$product, $a]))->assertRedirect();

        Storage::disk('public')->assertMissing($a->path);
        Storage::disk('public')->assertExists($b->path);
        $this->assertDatabaseMissing('product_images', ['id' => $a->id]);
        $this->assertSame([$b->id, $c->id], $product->images()->pluck('id')->all());
        $this->assertSame([0, 1], $product->images()->pluck('sort_order')->all());
    }

    public function test_a_seller_can_choose_another_photo_as_the_cover(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller);
        $this->upload($seller, $product, [UploadedFile::fake()->image('a.jpg', 400, 400), UploadedFile::fake()->image('b.jpg', 400, 400), UploadedFile::fake()->image('c.jpg', 400, 400)]);
        [$a, $b, $c] = $product->images()->get()->all();

        $this->actingAs($seller)->patch(route('seller.products.images.cover', [$product, $c]))->assertRedirect();

        $this->assertSame([$c->id, $a->id, $b->id], $product->images()->pluck('id')->all());
        $this->assertSame([0, 1, 2], $product->images()->pluck('sort_order')->all());
    }

    public function test_another_seller_cannot_delete_or_reorder_photos(): void
    {
        $owner = $this->makeSeller();
        $intruder = $this->makeSeller();
        $product = $this->makeListing($owner);
        $this->upload($owner, $product, [UploadedFile::fake()->image('a.jpg', 400, 400), UploadedFile::fake()->image('b.jpg', 400, 400)]);
        [$a, $b] = $product->images()->get()->all();

        $this->actingAs($intruder)->delete(route('seller.products.images.destroy', [$product, $a]))->assertForbidden();
        $this->actingAs($intruder)->patch(route('seller.products.images.cover', [$product, $b]))->assertForbidden();

        $this->assertSame(2, $product->images()->count());
        Storage::disk('public')->assertExists($a->path);
    }

    public function test_a_photo_cannot_be_addressed_through_a_different_listing(): void
    {
        $seller = $this->makeSeller();
        $mine = $this->makeListing($seller);
        $other = $this->makeListing($seller);
        $this->upload($seller, $other, [UploadedFile::fake()->image('a.jpg', 400, 400)]);
        $image = $other->images()->first();

        $this->actingAs($seller)->delete(route('seller.products.images.destroy', [$mine, $image]))->assertNotFound();

        $this->assertDatabaseHas('product_images', ['id' => $image->id]);
    }

    public function test_deleting_a_listing_removes_its_photo_files_and_rows(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller);
        $this->upload($seller, $product, [UploadedFile::fake()->image('a.jpg', 400, 400), UploadedFile::fake()->image('b.jpg', 400, 400)]);
        $paths = $product->images()->pluck('path')->all();

        $this->actingAs($seller)->delete(route('seller.products.destroy', $product))->assertRedirect();

        foreach ($paths as $path) {
            Storage::disk('public')->assertMissing($path);
        }
        $this->assertSame(0, ProductImage::where('product_id', $product->id)->count());
    }

    public function test_hard_deleting_a_listing_also_removes_its_files(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller);
        $this->upload($seller, $product, [UploadedFile::fake()->image('a.jpg', 400, 400)]);
        $path = $product->images()->first()->path;

        $product->forceDelete();

        Storage::disk('public')->assertMissing($path);
    }

    public function test_photos_can_be_added_while_creating_a_listing(): void
    {
        $seller = $this->makeSeller();

        $this->actingAs($seller)->post(route('seller.products.store'), [
            'name' => 'Listing created with photos',
            'price' => 10,
            'stock' => 3,
            'images' => [UploadedFile::fake()->image('a.jpg', 400, 400), UploadedFile::fake()->image('b.png', 400, 400)],
        ])->assertSessionHasNoErrors();

        $product = Product::where('name', 'Listing created with photos')->firstOrFail();
        $this->assertSame(2, $product->images()->count());
    }

    public function test_a_bad_photo_on_create_stops_the_listing_being_created(): void
    {
        $seller = $this->makeSeller();

        $this->actingAs($seller)->post(route('seller.products.store'), [
            'name' => 'Listing with a bad photo',
            'price' => 10,
            'stock' => 3,
            'images' => [UploadedFile::fake()->createWithContent('x.jpg', 'not an image')],
        ])->assertSessionHasErrors('images.0');

        $this->assertDatabaseMissing('products', ['name' => 'Listing with a bad photo']);
    }

    public function test_the_cover_shows_on_the_browse_card_and_every_photo_on_the_product_page(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller, ['name' => 'Photographed Pewter Candlestick']);
        $this->upload($seller, $product, [UploadedFile::fake()->image('a.jpg', 400, 400), UploadedFile::fake()->image('b.jpg', 400, 400)]);
        [$cover, $second] = $product->images()->get()->all();

        $browse = $this->get(route('products.index'))->assertOk();
        $browse->assertSee($cover->url(), false);
        $browse->assertDontSee($second->url(), false);

        $page = $this->get(route('products.show', $product))->assertOk();
        $page->assertSee($cover->url(), false);
        $page->assertSee($second->url(), false);
    }
}
