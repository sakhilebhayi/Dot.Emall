<?php

namespace Tests\Feature\QaBuyer;

use App\Services\Community\ReviewPhotos;
use App\Services\Returns\ReturnPhotos;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** A photo the web server itself refuses for size is reported as too big, not as a vague failed upload (R-43 buyer QA). */
class UploadLimitMessageTest extends TestCase
{
    private function tooBigForPhp(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'qa');
        file_put_contents($path, 'x');

        return new UploadedFile($path, 'huge.png', 'image/png', UPLOAD_ERR_INI_SIZE, true);
    }

    private function brokenUpload(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'qa');

        return new UploadedFile($path, 'half.png', 'image/png', UPLOAD_ERR_PARTIAL, true);
    }

    public function test_a_return_photo_over_the_server_limit_says_it_is_too_big(): void
    {
        try {
            app(ReturnPhotos::class)->validate([$this->tooBigForPhp()]);
            $this->fail('expected a validation error');
        } catch (ValidationException $e) {
            $this->assertSame('Each photo can be at most 4 MB.', $e->errors()['photos'][0]);
        }
    }

    public function test_a_return_photo_that_broke_in_transit_still_says_try_again(): void
    {
        try {
            app(ReturnPhotos::class)->validate([$this->brokenUpload()]);
            $this->fail('expected a validation error');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('did not upload', $e->errors()['photos'][0]);
        }
    }

    public function test_a_review_photo_over_the_server_limit_says_it_is_too_big(): void
    {
        try {
            app(ReviewPhotos::class)->validate([$this->tooBigForPhp()]);
            $this->fail('expected a validation error');
        } catch (ValidationException $e) {
            $this->assertSame('Photos can be at most 4 MB each.', $e->errors()['photos'][0]);
        }
    }
}
