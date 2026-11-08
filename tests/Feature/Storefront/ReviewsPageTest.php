<?php

namespace Tests\Feature\Storefront;

use App\Models\ContentReport;
use App\Models\Review;
use App\Models\ReviewPhoto;
use App\Models\ReviewResponse;
use App\Models\ReviewVote;
use App\Models\User;
use App\Services\Community\ReviewPhotos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\BuildsStorefront;
use Tests\TestCase;

/** Reviews on the product page: summary, list, eligibility, the verified label, votes, reports, edits, photos, replies. */
class ReviewsPageTest extends TestCase
{
    use BuildsMarketplace;
    use BuildsStorefront;
    use RefreshDatabase;

    private User $seller;

    private $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seller = $this->makeSeller(['name' => 'Review Test Store']);
        $this->product = $this->makeListing($this->seller, ['name' => 'Reviewed Kettle Distinct', 'price' => 150, 'stock' => 20]);
    }

    private function review(int $rating, ?string $body = null, bool $verified = true, array $extra = []): Review
    {
        $user = $this->makeBuyer();
        $review = new Review(['product_id' => $this->product->id, 'user_id' => $user->id, 'rating' => $rating, 'body' => $body]);
        $review->forceFill(array_merge(['verified_purchase' => $verified, 'status' => Review::PUBLISHED], $extra))->save();
        app(\App\Services\ReviewService::class)->refreshAggregate($this->product);

        return $review;
    }

    private function png(string $name = 'photo.png'): UploadedFile
    {
        $image = imagecreatetruecolor(40, 40);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();

        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    public function test_nothing_about_ratings_is_shown_for_a_product_with_no_reviews(): void
    {
        $html = $this->get(route('products.show', $this->product))->assertOk()->getContent();

        $this->assertStringContainsString('No reviews yet', $html);
        $this->assertStringNotContainsString('Rated 0', $html);
        $this->assertStringNotContainsString('Reviews by star rating', $html);
        $this->assertStringNotContainsString('aggregateRating', $html);
    }

    public function test_the_summary_comes_from_real_published_reviews_only(): void
    {
        $this->review(5, 'Excellent kettle.');
        $this->review(5, 'Love it.');
        $this->review(2, 'Meh.', false);
        $this->review(1, 'Held review text that nobody has seen', true, ['status' => Review::HELD]);
        $this->review(1, 'Removed review text', true, ['status' => Review::REMOVED]);

        $html = $this->get(route('products.show', $this->product))->assertOk()->assertSee('Reviews by star rating', false)->getContent();

        $this->assertStringContainsString('3 reviews', $html);
        $this->assertStringContainsString('4.0', $html, '(5 + 5 + 2) / 3, to one decimal');
        $this->assertStringNotContainsString('Held review text', $html);
        $this->assertStringNotContainsString('Removed review text', $html);
        $this->assertStringContainsString('2 of 3', $html, 'two are verified purchases');
    }

    public function test_the_verified_label_comes_only_from_the_stored_flag(): void
    {
        $this->review(5, 'A verified one', true);
        $this->review(4, 'An unverified one', false);

        $html = $this->get(route('products.show', $this->product))->getContent();

        $this->assertSame(1, substr_count($html, 'Verified purchase</span>'), 'one review earned the badge');
    }

    public function test_reviews_sort_and_filter(): void
    {
        $this->review(3, 'Middling alpha note', true, ['helpful_count' => 1]);
        $this->review(5, 'Brilliant bravo note', false, ['helpful_count' => 9]);
        $this->review(1, 'Awful charlie note', true, ['helpful_count' => 0]);
        $order = fn (string $sort) => array_values(array_filter(array_map(
            fn ($word) => strpos($this->get(route('products.show', ['product' => $this->product, 'reviews_sort' => $sort]))->getContent(), $word),
            ['alpha', 'bravo', 'charlie'],
        )));
        $positions = function (string $sort) {
            $html = $this->get(route('products.show', ['product' => $this->product, 'reviews_sort' => $sort]))->getContent();
            $p = ['alpha' => strpos($html, 'Middling alpha'), 'bravo' => strpos($html, 'Brilliant bravo'), 'charlie' => strpos($html, 'Awful charlie')];
            asort($p);

            return array_keys($p);
        };

        $this->assertSame(['bravo', 'alpha', 'charlie'], $positions('helpful'));
        $this->assertSame(['bravo', 'alpha', 'charlie'], $positions('highest'));
        $this->assertSame(['charlie', 'alpha', 'bravo'], $positions('lowest'));
        $this->assertSame(['charlie', 'bravo', 'alpha'], $positions('newest'));

        $verified = $this->get(route('products.show', ['product' => $this->product, 'reviews_verified' => 1]))->getContent();
        $this->assertStringContainsString('Middling alpha', $verified);
        $this->assertStringNotContainsString('Brilliant bravo', $verified);

        $this->get(route('products.show', ['product' => $this->product, 'reviews_sort' => 'nonsense']))->assertOk();
    }

    public function test_a_buyer_with_a_delivered_order_may_write_a_review_and_it_is_verified(): void
    {
        $buyer = $this->deliveredBuyerOf($this->product);

        $this->actingAs($buyer)->get(route('products.show', $this->product))->assertSee('Write a review')->assertSee('your review will say');
        $this->actingAs($buyer)->post(route('reviews.store', $this->product), ['rating' => 5, 'body' => 'Boils quickly.'])->assertRedirect();

        $review = Review::query()->firstOrFail();
        $this->assertTrue($review->verified_purchase);
        $this->assertNotNull($review->order_item_id);

        $this->actingAs($buyer)->get(route('products.show', $this->product))->assertDontSee('Write a review')->assertSee('Your review is on the page below');
    }

    public function test_a_request_cannot_set_the_verified_flag(): void
    {
        $buyer = $this->deliveredBuyerOf($this->product);
        $this->actingAs($buyer)->post(route('reviews.store', $this->product), ['rating' => 4, 'verified_purchase' => 0, 'status' => 'held', 'helpful_count' => 99]);

        $review = Review::query()->firstOrFail();
        $this->assertTrue($review->verified_purchase);
        $this->assertSame(Review::PUBLISHED, $review->status);
        $this->assertSame(0, (int) $review->helpful_count);
    }

    public function test_eligibility_matrix_says_why_in_words(): void
    {
        $this->get(route('products.show', $this->product))->assertSee('Sign in</a>', false)->assertDontSee('Write a review');

        $stranger = $this->makeBuyer();
        $this->actingAs($stranger)->get(route('products.show', $this->product))->assertSee('Only people whose order for this product was delivered or collected')->assertDontSee('Write a review');
        $this->actingAs($stranger)->post(route('reviews.store', $this->product), ['rating' => 5])->assertForbidden();

        $waiting = $this->makeBuyer();
        $this->placeOrder($waiting, [[$this->product, 1]]);
        $this->actingAs($waiting)->get(route('products.show', $this->product))->assertSee('once your order has been delivered or collected')->assertDontSee('Write a review');
        $this->actingAs($waiting)->post(route('reviews.store', $this->product), ['rating' => 5])->assertForbidden();

        $this->actingAs($this->seller)->get(route('products.show', $this->product))->assertSee('you cannot review it');
    }

    public function test_a_held_review_is_shown_to_its_author_only_as_waiting_for_review(): void
    {
        $held = $this->review(1, 'Copied text held for a person to read', true, ['status' => Review::HELD, 'held_reason' => 'copied']);

        $own = $this->actingAs(User::find($held->user_id))->get(route('products.show', $this->product))->assertSee('Waiting for review')->assertSee('No reviews yet')->getContent();
        $this->assertStringNotContainsString('id="review-'.$held->id.'"', $own, 'it is not in the public list, only in its author\'s own edit form');
        $this->actingAs($this->makeBuyer())->get(route('products.show', $this->product))->assertDontSee('Waiting for review');
        $this->get(route('products.show', $this->product))->assertDontSee('Copied text held');
    }

    public function test_helpful_votes_need_a_sign_in_one_per_person_and_never_on_your_own_review(): void
    {
        $review = $this->review(5, 'Votable review text');
        $voter = $this->makeBuyer();

        $this->post(route('reviews.vote', $review))->assertRedirect(route('login'));

        $this->actingAs($voter)->post(route('reviews.vote', $review))->assertRedirect();
        $this->assertSame(1, (int) $review->fresh()->helpful_count);

        $this->actingAs($voter)->post(route('reviews.vote', $review))->assertRedirect();
        $this->assertSame(0, (int) $review->fresh()->helpful_count, 'the same person again takes the vote back');
        $this->assertSame(0, ReviewVote::count());

        $this->actingAs(User::find($review->user_id))->post(route('reviews.vote', $review))->assertForbidden();

        $this->actingAs($voter)->postJson(route('reviews.vote', $review))->assertOk()->assertJson(['voted' => true, 'count' => 1]);
    }

    public function test_a_held_review_cannot_be_voted_on_or_reported(): void
    {
        $held = $this->review(2, 'Held', true, ['status' => Review::HELD]);
        $someone = $this->makeBuyer();

        $this->actingAs($someone)->post(route('reviews.vote', $held))->assertForbidden();
        $this->actingAs($someone)->post(route('reviews.report', $held), ['reason' => 'spam'])->assertForbidden();
    }

    public function test_reporting_a_review_reaches_the_operator_queue_once_and_hides_nothing(): void
    {
        $review = $this->review(1, 'Possibly fake review text');
        $reporter = $this->makeBuyer();

        $this->actingAs($reporter)->post(route('reviews.report', $review), ['reason' => 'fake', 'details' => 'Same words as another review'])->assertRedirect()->assertSessionHas('status');
        $this->assertSame(1, ContentReport::where('reportable_type', 'review')->where('reportable_id', $review->id)->count());
        $this->assertSame(Review::PUBLISHED, $review->fresh()->status);

        $this->actingAs($reporter)->post(route('reviews.report', $review), ['reason' => 'fake'])->assertSessionHasErrors('reason', null, 'reportreview'.$review->id);
        $this->assertSame(1, ContentReport::count());

        $this->actingAs($this->makeBuyer())->post(route('reviews.report', $review), ['reason' => 'because'])->assertSessionHasErrors('reason', null, 'reportreview'.$review->id);
        $this->app['auth']->forgetGuards();
        $this->post(route('reviews.report', $review), ['reason' => 'spam'])->assertRedirect(route('login'));
        $this->actingAs(User::find($review->user_id))->post(route('reviews.report', $review), ['reason' => 'spam'])->assertForbidden();
    }

    public function test_the_author_edits_within_the_window_and_not_after_and_nobody_else_can(): void
    {
        $buyer = $this->deliveredBuyerOf($this->product);
        $this->actingAs($buyer)->post(route('reviews.store', $this->product), ['rating' => 3, 'body' => 'Fine for the price.']);
        $review = Review::firstOrFail();

        $this->actingAs($this->makeBuyer())->patch(route('reviews.update', $review), ['rating' => 1])->assertForbidden();

        $this->actingAs($buyer)->patch(route('reviews.update', $review), ['rating' => 5, 'body' => 'Better after a month of use.'])->assertRedirect();
        $this->assertSame(5, $review->fresh()->rating);
        $this->assertNotNull($review->fresh()->edited_at);
        $this->assertSame(5, (int) $this->product->fresh()->rating_average, 'the aggregate follows the edit');

        Carbon::setTestNow(now()->addDays(31));
        $this->actingAs($buyer)->patch(route('reviews.update', $review), ['rating' => 1])->assertForbidden();
        $this->assertSame(5, $review->fresh()->rating);
        Carbon::setTestNow();
    }

    public function test_a_photo_is_uploaded_re_encoded_and_served_from_the_private_disk(): void
    {
        Storage::fake(ReviewPhotos::DISK);
        $buyer = $this->deliveredBuyerOf($this->product);

        $this->actingAs($buyer)->post(route('reviews.store', $this->product), ['rating' => 5, 'body' => 'With a picture.', 'photos' => [$this->png()]])->assertRedirect();

        $photo = ReviewPhoto::firstOrFail();
        Storage::disk(ReviewPhotos::DISK)->assertExists($photo->path);
        $this->assertStringEndsWith('.jpg', $photo->path, 're-encoded, so a file that only looked like a picture is gone');

        $html = $this->get(route('products.show', $this->product))->getContent();
        $this->assertStringContainsString(route('review-photos.show', $photo), $html);
        $this->assertStringContainsString('data-lightbox', $html);
        $this->get(route('review-photos.show', $photo))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_a_file_that_is_not_an_image_is_refused_whatever_its_name(): void
    {
        Storage::fake(ReviewPhotos::DISK);
        $buyer = $this->deliveredBuyerOf($this->product);
        $fake = UploadedFile::fake()->createWithContent('holiday.jpg', '<?php echo "not an image"; ?>');

        $this->actingAs($buyer)->post(route('reviews.store', $this->product), ['rating' => 5, 'photos' => [$fake]])->assertSessionHasErrors('photos');
        $this->assertSame(0, Review::count());
        $this->assertSame(0, ReviewPhoto::count());
    }

    public function test_more_than_four_photos_or_an_oversize_one_is_refused(): void
    {
        Storage::fake(ReviewPhotos::DISK);
        $buyer = $this->deliveredBuyerOf($this->product);

        $this->actingAs($buyer)->post(route('reviews.store', $this->product), ['rating' => 5, 'photos' => [$this->png('1.png'), $this->png('2.png'), $this->png('3.png'), $this->png('4.png'), $this->png('5.png')]])->assertSessionHasErrors('photos');

        $big = UploadedFile::fake()->image('big.jpg')->size(5000);
        $this->actingAs($buyer)->post(route('reviews.store', $this->product), ['rating' => 5, 'photos' => [$big]])->assertSessionHasErrors('photos');
        $this->assertSame(0, Review::count());
    }

    public function test_a_held_review_photo_is_not_public(): void
    {
        Storage::fake(ReviewPhotos::DISK);
        $held = $this->review(2, 'held with photo', true, ['status' => Review::HELD]);
        app(ReviewPhotos::class)->attach($held, [$this->png()]);
        $photo = ReviewPhoto::firstOrFail();

        $this->get(route('review-photos.show', $photo))->assertNotFound();
        $this->actingAs(User::find($held->user_id))->get(route('review-photos.show', $photo))->assertOk();
    }

    public function test_only_the_store_replies_once_and_the_reply_shows_under_the_review(): void
    {
        $review = $this->review(2, 'Arrived with a dent');
        $stranger = $this->makeBuyer();

        $this->actingAs($stranger)->post(route('reviews.reply', $review), ['body' => 'I can reply'])->assertForbidden();

        $this->actingAs($this->seller)->get(route('products.show', $this->product))->assertSee('Reply as Review Test Store');
        $this->actingAs($this->seller)->post(route('reviews.reply', $review), ['body' => 'Sorry about the dent, we will send a new one.'])->assertRedirect();
        $this->assertSame(1, ReviewResponse::count());

        $this->actingAs($this->seller)->post(route('reviews.reply', $review), ['body' => 'Second reply'])->assertSessionHasErrors('body', null, 'reply'.$review->id);
        $this->assertSame(1, ReviewResponse::count());

        $this->get(route('products.show', $this->product))->assertSee('Reply from Review Test Store')->assertSee('Sorry about the dent');
        $this->actingAs($this->seller)->get(route('products.show', $this->product))->assertDontSee('Reply as Review Test Store');
    }

    public function test_a_hidden_seller_reply_is_not_shown(): void
    {
        $review = $this->review(2, 'Arrived with a dent');
        ReviewResponse::query()->create(['review_id' => $review->id, 'user_id' => $this->seller->id, 'store_id' => $this->seller->store->id, 'body' => 'A reply the operator hid']);
        ReviewResponse::query()->update(['status' => 'hidden']);

        $this->get(route('products.show', $this->product))->assertDontSee('A reply the operator hid');
    }

    public function test_the_author_can_delete_their_review_and_the_rating_follows(): void
    {
        $review = $this->review(1, 'Terrible');
        $other = $this->review(5, 'Great');

        $this->actingAs(User::find($review->user_id))->delete(route('reviews.destroy', $review))->assertRedirect();
        $this->assertSame(1, Review::count());
        $this->assertSame(5, (int) $this->product->fresh()->rating_average);
    }

    public function test_pagination_keeps_the_sort_and_does_not_repeat_reviews(): void
    {
        foreach (range(1, 8) as $n) {
            $this->review(($n % 5) + 1, "Numbered review body {$n} xx");
        }

        $first = $this->get(route('products.show', ['product' => $this->product, 'reviews_sort' => 'newest']))->getContent();
        $second = $this->get(route('products.show', ['product' => $this->product, 'reviews_sort' => 'newest', 'reviews_page' => 2]))->getContent();

        $this->assertStringContainsString('Numbered review body 8 xx', $first);
        $this->assertStringNotContainsString('Numbered review body 8 xx', $second);
        $this->assertStringContainsString('Numbered review body 1 xx', $second);
        $this->assertStringContainsString('reviews_sort=newest', $first, 'the page links keep the sort');
    }
}
