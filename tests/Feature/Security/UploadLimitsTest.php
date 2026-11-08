<?php

namespace Tests\Feature\Security;

use App\Services\Community\ReviewPhotos;
use App\Services\Returns\ReturnPhotos;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * R-43 security pass. A PNG of one solid colour compresses about a thousand to one, so a 250 KB upload (well under the 4 MB cap) can
 * describe a picture of 80 million pixels; decoding it costs gigabytes in GD's own allocator, which PHP's memory_limit does not count.
 * The size must be read from the header and refused BEFORE anything is decoded.
 */
class UploadLimitsTest extends TestCase
{
    /** A valid solid-colour RGB PNG, built row by row so the test itself never holds the raw pixels. */
    private function hugePng(int $side): UploadedFile
    {
        $deflate = deflate_init(ZLIB_ENCODING_DEFLATE, ['level' => 9]);
        $row = str_repeat("\0", 1 + $side * 3);
        $data = '';

        for ($y = 0; $y < $side; $y++) {
            $data .= deflate_add($deflate, $row, ZLIB_NO_FLUSH);
        }

        $data .= deflate_add($deflate, '', ZLIB_FINISH);
        $chunk = fn (string $type, string $body) => pack('N', strlen($body)).$type.$body.pack('N', crc32($type.$body));
        $png = "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $side, $side, 8, 2, 0, 0, 0)).$chunk('IDAT', $data).$chunk('IEND', '');

        $this->assertLessThan(1024 * 1024, strlen($png), 'the upload is far under the 4 MB cap');

        return UploadedFile::fake()->createWithContent('huge.png', $png);
    }

    public function test_a_review_photo_that_describes_a_huge_picture_is_refused_before_it_is_decoded(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('too large');

        app(ReviewPhotos::class)->validate([$this->hugePng(9000)]);
    }

    public function test_a_return_photo_that_describes_a_huge_picture_is_refused_before_it_is_decoded(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('too large');

        app(ReturnPhotos::class)->validate([$this->hugePng(9000)]);
    }

    public function test_an_ordinary_photo_still_passes(): void
    {
        $image = imagecreatetruecolor(800, 600);
        ob_start();
        imagejpeg($image, null, 80);
        $jpeg = UploadedFile::fake()->createWithContent('ok.jpg', (string) ob_get_clean());

        app(ReviewPhotos::class)->validate([$jpeg]);
        app(ReturnPhotos::class)->validate([$jpeg]);

        $this->addToAssertionCount(1);
    }
}
