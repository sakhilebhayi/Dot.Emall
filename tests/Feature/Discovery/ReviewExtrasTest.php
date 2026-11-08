<?php

namespace Tests\Feature\Discovery;

use App\Enums\OrderStatus;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Review;
use App\Models\ReviewPhoto;
use App\Models\ReviewResponse;
use App\Models\Store;
use App\Models\User;
use App\Notifications\NewReviewNotification;
use App\Services\Community\ContentReports;
use App\Services\Community\ModerationQueue;
use App\Services\Community\ReviewResponses;
use App\Services\Community\ReviewVotes;
use App\Services\Community\StoreRatings;
use App\Services\ReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsMarketplace;
use Tests\TestCase;

class ReviewExtrasTest extends TestCase
{
    use BuildsMarketplace;
    use RefreshDatabase;

    private User $seller;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seller = $this->makeSeller();
        $this->product = $this->makeListing($this->seller, ['name' => 'Cast Iron Potjie']);
    }

    private function buyerWithDelivered(?Product $product = null): User
    {
        $buyer = $this->makeBuyer();
        $order = $this->placeOrder($buyer, [[$product ?? $this->product, 1]]);
        $order->items()->update(['status' => OrderStatus::Delivered->value]);

        return $buyer;
    }

    private function operator(): User
    {
        return User::factory()->create(['is_platform_operator' => true]);
    }

    public function test_verified_purchase_is_derived_from_a_delivered_order_line(): void
    {
        $buyer = $this->buyerWithDelivered();

        $review = app(ReviewService::class)->submit($buyer, $this->product, 5, 'Perfect potjie, seasoned well.');

        $this->assertTrue($review->verified_purchase);
        $this->assertNotNull($review->order_item_id);
        $this->assertSame(OrderStatus::Delivered, OrderItem::find($review->order_item_id)->status);
    }

    public function test_the_flag_cannot_be_set_by_the_client(): void
    {
        $buyer = $this->buyerWithDelivered();

        $this->actingAs($buyer)->post(route('reviews.store', $this->product), [
            'rating' => 4, 'body' => 'Good', 'verified_purchase' => 0, 'order_item_id' => 9999, 'status' => 'removed', 'helpful_count' => 50,
        ])->assertRedirect();

        $review = Review::first();
        $this->assertTrue($review->verified_purchase);
        $this->assertNotSame(9999, $review->order_item_id);
        $this->assertSame('published', $review->status);
        $this->assertSame(0, $review->helpful_count);
    }

    public function test_an_unverified_review_made_outside_the_controller_is_flagged_as_such(): void
    {
        $review = app(ReviewService::class)->submit($this->makeBuyer(), $this->product, 3, 'Bought elsewhere, same model.');

        $this->assertFalse($review->verified_purchase);
        $this->assertNull($review->order_item_id);
    }

    public function test_only_published_reviews_move_the_aggregates(): void
    {
        $service = app(ReviewService::class);
        $service->submit($this->buyerWithDelivered(), $this->product, 5, 'Excellent product, would buy again.');
        $second = $service->submit($this->buyerWithDelivered(), $this->product, 1, 'Awful and nothing like the photos.');

        $this->assertSame(2, $this->product->fresh()->rating_count);

        $service->moderate($this->operator(), $second, Review::REMOVED);
        $product = $this->product->fresh();

        $this->assertSame(1, $product->rating_count);
        $this->assertSame('5.00', $product->rating_average);
    }

    public function test_too_many_reviews_in_a_day_are_held_and_do_not_count(): void
    {
        config(['discovery.reviews.max_reviews_per_day' => 2]);
        $buyer = $this->makeBuyer();
        $service = app(ReviewService::class);
        $products = collect(range(1, 3))->map(fn ($i) => $this->makeListing($this->seller, ['name' => 'Garden Item '.$i]));

        $texts = ['Sturdy spade, handle is comfortable.', 'The hose reel winds smoothly every time.', 'Pruning shears are sharp enough for roses.'];
        $reviews = $products->map(fn ($p, $i) => $service->submit($buyer, $p, 5, $texts[$i]));

        $this->assertSame(['published', 'published', 'held'], $reviews->pluck('status')->all());
        $this->assertSame('too_many_today', $reviews[2]->held_reason);
        $this->assertSame(0, $products[2]->fresh()->rating_count);

        $service->moderate($this->operator(), $reviews[2], Review::PUBLISHED);
        $this->assertSame(1, $products[2]->fresh()->rating_count);
    }

    public function test_copied_text_is_held(): void
    {
        $service = app(ReviewService::class);
        $a = $this->makeListing($this->seller, ['name' => 'Item A Pan']);
        $b = $this->makeListing($this->seller, ['name' => 'Item B Pot']);
        $buyer = $this->makeBuyer();
        $text = 'Absolutely the best purchase I have ever made, highly recommended to everyone.';

        $first = $service->submit($buyer, $a, 5, $text);
        $second = $service->submit($buyer, $b, 5, $text);
        $third = $service->submit($this->makeBuyer(), $this->product, 5, $text);

        $this->assertSame('published', $first->status);
        $this->assertSame('held', $second->status);
        $this->assertSame('duplicate_text', $second->held_reason);
        $this->assertSame('held', $third->status, 'long identical text from a different account is held too');
    }

    public function test_nearly_identical_text_by_the_same_person_is_held(): void
    {
        $service = app(ReviewService::class);
        $a = $this->makeListing($this->seller, ['name' => 'Item A Pan']);
        $b = $this->makeListing($this->seller, ['name' => 'Item B Pot']);
        $buyer = $this->makeBuyer();

        $service->submit($buyer, $a, 5, 'Really good quality, arrived quickly and well packed.');
        $second = $service->submit($buyer, $b, 5, 'Really good quality, arrived quickly and well packed!!');

        $this->assertSame('held', $second->status);
    }

    public function test_a_held_review_is_not_shown_and_the_seller_is_not_notified(): void
    {
        config(['discovery.reviews.max_reviews_per_day' => 0]);
        Notification::fake();
        $buyer = $this->buyerWithDelivered();

        $this->actingAs($buyer)->post(route('reviews.store', $this->product), ['rating' => 5, 'body' => 'Lovely potjie.'])->assertRedirect();
        Notification::assertNotSentTo($this->seller, NewReviewNotification::class);

        $this->assertSame(0, $this->product->fresh()->rating_count);
        $this->get(route('products.show', $this->product))->assertDontSee('Lovely potjie.');
    }

    public function test_helpfulness_one_vote_per_person_never_on_your_own(): void
    {
        $author = $this->buyerWithDelivered();
        $review = app(ReviewService::class)->submit($author, $this->product, 5, 'Excellent product, would buy again.');
        $voter = $this->makeBuyer();
        $votes = app(ReviewVotes::class);

        $this->assertTrue($votes->toggle($voter, $review));
        $this->assertSame(1, $review->fresh()->helpful_count);
        $this->assertFalse($votes->toggle($voter, $review->fresh()), 'the same person again takes the vote back');
        $this->assertSame(0, $review->fresh()->helpful_count);

        $votes->toggle($voter, $review->fresh());
        $votes->toggle($this->makeBuyer(), $review->fresh());
        $this->assertSame(2, $review->fresh()->helpful_count);
        $this->assertSame(2, DB::table('review_votes')->count());

        $this->expectException(ValidationException::class);
        $votes->toggle($author, $review);
    }

    public function test_one_seller_response_per_review_by_the_store_only_and_no_deleting(): void
    {
        $review = app(ReviewService::class)->submit($this->buyerWithDelivered(), $this->product, 2, 'Handle was loose on arrival.');
        $responses = app(ReviewResponses::class);

        try {
            $responses->respond($this->makeBuyer(), $review, 'I am a stranger.');
            $this->fail('stranger replied');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        $reply = $responses->respond($this->seller, $review, 'Sorry about that, we have sent a replacement.');
        $this->assertSame($review->id, $reply->review_id);

        try {
            $responses->respond($this->seller, $review, 'Second reply.');
            $this->fail('a second reply');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('already has a reply', $e->errors()['body'][0]);
        }

        $this->assertFalse($this->seller->can('delete', $reply) ?? false);
        $this->assertSame(1, ReviewResponse::count());
        $this->assertTrue($this->seller->can('respond', $review));
        $this->assertFalse($this->makeBuyer()->can('respond', $review));
    }

    public function test_the_author_can_edit_inside_the_window_only(): void
    {
        $author = $this->buyerWithDelivered();
        $service = app(ReviewService::class);
        $review = $service->submit($author, $this->product, 5, 'Excellent product, would buy again.');

        $edited = $service->update($author, $review, 3, 'On reflection the lid is thin.');
        $this->assertSame(3, $edited->rating);
        $this->assertNotNull($edited->edited_at);
        $this->assertSame('3.00', $this->product->fresh()->rating_average);

        try {
            $service->update($this->makeBuyer(), $review, 1, 'Not mine.');
            $this->fail('someone else edited');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        $this->travel(31)->days();
        $this->assertFalse($author->can('update', $review->fresh()));

        $this->expectException(ValidationException::class);
        $service->update($author, $review->fresh(), 4, 'Too late.');
    }

    public function test_photos_are_private_re_encoded_and_limited(): void
    {
        $buyer = $this->buyerWithDelivered();
        $photo = UploadedFile::fake()->image('potjie.jpg', 800, 600);

        $review = app(ReviewService::class)->submit($buyer, $this->product, 5, 'Excellent product, would buy again.', [$photo]);

        $stored = ReviewPhoto::first();
        $this->assertSame(1, $review->photos()->count());
        $this->assertStringStartsWith('review-photos/'.$review->id.'/', $stored->path);
        $this->assertStringNotContainsString('potjie', $stored->path);
        Storage::disk('local')->assertExists($stored->path);
        Storage::disk('public')->assertMissing($stored->path);

        $this->get(route('review-photos.show', $stored))->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        try {
            app(ReviewService::class)->submit($this->buyerWithDelivered(), $this->product, 4, 'Fine.', array_fill(0, 5, UploadedFile::fake()->image('a.jpg')));
            $this->fail('five photos');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('photos', $e->errors());
        }

        try {
            app(ReviewService::class)->submit($this->buyerWithDelivered(), $this->product, 4, 'Fine.', [UploadedFile::fake()->create('virus.php', 5, 'application/x-php')]);
            $this->fail('a php file');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('photos', $e->errors());
        }
    }

    public function test_photos_of_a_held_review_are_not_public_and_deleting_the_review_removes_the_files(): void
    {
        config(['discovery.reviews.max_reviews_per_day' => 0]);
        $buyer = $this->buyerWithDelivered();
        $review = app(ReviewService::class)->submit($buyer, $this->product, 5, 'Excellent product, would buy again.', [UploadedFile::fake()->image('a.jpg')]);
        $photo = $review->photos()->first();

        $this->get(route('review-photos.show', $photo))->assertNotFound();
        $this->actingAs($this->makeBuyer())->get(route('review-photos.show', $photo))->assertNotFound();
        $this->actingAs($buyer)->get(route('review-photos.show', $photo))->assertOk();
        $this->actingAs($this->operator())->get(route('review-photos.show', $photo))->assertOk();

        app(ReviewService::class)->delete($review);
        Storage::disk('local')->assertMissing($photo->path);
    }

    public function test_reporting_a_review_reaches_the_operator_queue(): void
    {
        $review = app(ReviewService::class)->submit($this->buyerWithDelivered(), $this->product, 1, 'Awful and nothing like the photos.');

        app(ContentReports::class)->report($this->seller, 'review', $review->id, 'fake', 'never ordered this');

        $queue = app(ModerationQueue::class)->reported();
        $this->assertSame('review', $queue->first()['type']);
        $this->assertSame('Awful and nothing like the photos.', $queue->first()['excerpt']);
        $this->assertSame(1, app(ModerationQueue::class)->counts()['reported']);
    }

    public function test_a_store_rating_is_shown_only_from_enough_real_reviews(): void
    {
        $service = app(ReviewService::class);
        $ratings = app(StoreRatings::class);
        $store = $this->seller->store;

        foreach (range(1, 4) as $i) {
            $service->submit($this->buyerWithDelivered(), $this->product, 5, 'Review number '.$i.' of the potjie, all good.');
        }

        $summary = $ratings->summary($store);
        $this->assertSame(4, $summary['count']);
        $this->assertFalse($summary['shown']);
        $this->assertNull($summary['average']);

        $service->submit($this->buyerWithDelivered(), $this->product, 3, 'Review number 5 of the potjie, a bit heavy.');
        $summary = $ratings->summary($store);

        $this->assertTrue($summary['shown']);
        $this->assertSame(4.6, $summary['average']);
        $this->assertSame(['average' => null, 'count' => 0, 'shown' => false, 'minimum' => 5], $ratings->summary(Store::factory()->create()));
    }

    public function test_the_rebuild_command_repairs_drift_and_reports_in_dry_run(): void
    {
        $service = app(ReviewService::class);
        $service->submit($this->buyerWithDelivered(), $this->product, 4, 'Good potjie, well made indeed.');
        $service->submit($this->buyerWithDelivered(), $this->product, 2, 'Rusted after a month honestly.');
        DB::table('products')->where('id', $this->product->id)->update(['rating_count' => 99, 'rating_average' => 1.11]);
        DB::table('store_rating_aggregates')->delete();

        $this->artisan('emall:reviews-rebuild', ['--dry-run' => true])->expectsOutputToContain('Would fix 1 of')->assertSuccessful();
        $this->assertSame(99, $this->product->fresh()->rating_count, 'dry run writes nothing');

        $this->artisan('emall:reviews-rebuild')->assertSuccessful();

        $product = $this->product->fresh();
        $this->assertSame(2, $product->rating_count);
        $this->assertSame('3.00', $product->rating_average);
        $this->assertSame(2, app(StoreRatings::class)->summary($this->seller->store)['count']);
    }

    public function test_existing_reviews_stay_published_and_the_listing_page_hides_held_ones(): void
    {
        $author = $this->buyerWithDelivered();
        $review = app(ReviewService::class)->submit($author, $this->product, 5, 'Visible praise for this potjie.');

        $this->get(route('products.show', $this->product))->assertSee('Visible praise for this potjie.');

        app(ReviewService::class)->moderate($this->operator(), $review, Review::REMOVED);
        $this->get(route('products.show', $this->product))->assertDontSee('Visible praise for this potjie.');
    }
}
