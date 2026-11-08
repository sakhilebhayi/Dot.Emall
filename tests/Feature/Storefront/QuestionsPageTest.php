<?php

namespace Tests\Feature\Storefront;

use App\Models\ContentReport;
use App\Models\ProductAnswer;
use App\Models\ProductQuestion;
use App\Models\User;
use App\Notifications\NewQuestionNotification;
use App\Notifications\QuestionAnsweredNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\BuildsStorefront;
use Tests\TestCase;

class QuestionsPageTest extends TestCase
{
    use BuildsMarketplace;
    use BuildsStorefront;
    use RefreshDatabase;

    private User $seller;

    private $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seller = $this->makeSeller(['name' => 'Drill Depot']);
        $this->product = $this->makeListing($this->seller, ['name' => 'Cordless Drill Distinct', 'stock' => 5]);
    }

    public function test_a_guest_reads_questions_and_is_told_to_sign_in_to_ask(): void
    {
        $asker = $this->makeBuyer();
        $q = ProductQuestion::factory()->create(['product_id' => $this->product->id, 'user_id' => $asker->id, 'body' => 'Does it come with a battery?']);
        ProductAnswer::query()->create(['question_id' => $q->id, 'user_id' => $this->seller->id, 'store_id' => $this->seller->store->id, 'body' => 'Yes, two batteries and a charger.', 'is_seller' => true]);

        $this->get(route('products.show', $this->product))->assertOk()
            ->assertSee('Does it come with a battery?')->assertSee('Yes, two batteries and a charger.')->assertSee('From Drill Depot')
            ->assertSee('to ask the store a question');
    }

    public function test_a_signed_in_shopper_asks_and_the_store_is_told(): void
    {
        Notification::fake();
        $buyer = $this->makeBuyer();

        $this->actingAs($buyer)->get(route('products.show', $this->product))->assertSee('Ask the store');
        $this->actingAs($buyer)->post(route('questions.store', $this->product), ['body' => 'How long is the warranty on it?'])->assertRedirect()->assertSessionHas('status');

        $this->assertSame(1, ProductQuestion::count());
        Notification::assertSentTo($this->seller, NewQuestionNotification::class);
    }

    public function test_the_ask_form_validates_on_the_server_and_a_second_question_the_same_day_is_refused(): void
    {
        $buyer = $this->makeBuyer();
        $this->actingAs($buyer)->post(route('questions.store', $this->product), ['body' => 'short'])->assertSessionHasErrors('body', null, 'ask');
        $this->actingAs($buyer)->post(route('questions.store', $this->product), [])->assertSessionHasErrors('body');

        $this->actingAs($buyer)->post(route('questions.store', $this->product), ['body' => 'Is this good for concrete walls?'])->assertSessionHasNoErrors();
        $this->actingAs($buyer)->post(route('questions.store', $this->product), ['body' => 'And what about the chuck size?'])->assertSessionHasErrors('body', null, 'ask');
        $this->assertSame(1, ProductQuestion::count());
    }

    public function test_a_question_with_contact_details_is_held_and_shown_only_to_its_asker(): void
    {
        $buyer = $this->makeBuyer();
        $this->actingAs($buyer)->post(route('questions.store', $this->product), ['body' => 'Call me on 082 555 0142 about price']);

        $this->assertSame(ProductQuestion::HELD, ProductQuestion::firstOrFail()->status);
        $this->actingAs($buyer)->get(route('products.show', $this->product))->assertSee('Waiting for review')->assertSee('Call me on 082');
        $this->actingAs($this->makeBuyer())->get(route('products.show', $this->product))->assertDontSee('Call me on 082');
        $this->app['auth']->forgetGuards();
        $this->get(route('products.show', $this->product))->assertDontSee('Call me on 082');
    }

    public function test_the_sellers_own_people_cannot_ask_and_only_they_can_answer(): void
    {
        $q = ProductQuestion::factory()->create(['product_id' => $this->product->id, 'user_id' => $this->makeBuyer()->id, 'body' => 'Is it left or right handed?']);

        $this->actingAs($this->seller)->post(route('questions.store', $this->product), ['body' => 'Asking my own product something'])->assertForbidden();

        $stranger = $this->makeBuyer();
        $this->actingAs($stranger)->post(route('questions.answer', $q), ['body' => 'I can answer for the store'])->assertForbidden();
        $this->assertSame(0, ProductAnswer::count());

        Notification::fake();
        $this->actingAs($this->seller)->get(route('products.show', $this->product))->assertSee('Answer as Drill Depot');
        $this->actingAs($this->seller)->post(route('questions.answer', $q), ['body' => 'Neither: it works both ways.'])->assertRedirect();

        $answer = ProductAnswer::firstOrFail();
        $this->assertTrue($answer->is_seller);
        Notification::assertSentTo($q->user, QuestionAnsweredNotification::class);
        $this->get(route('products.show', $this->product))->assertSee('From Drill Depot');
    }

    public function test_the_asker_withdraws_an_unanswered_question_but_not_an_answered_one_or_someone_elses(): void
    {
        $asker = $this->makeBuyer();
        $open = ProductQuestion::factory()->create(['product_id' => $this->product->id, 'user_id' => $asker->id]);
        $answered = ProductQuestion::factory()->create(['product_id' => $this->product->id, 'user_id' => $asker->id, 'answers_count' => 1]);

        $this->actingAs($this->makeBuyer())->delete(route('questions.destroy', $open))->assertForbidden();
        $this->actingAs($asker)->delete(route('questions.destroy', $answered))->assertForbidden();
        $this->actingAs($asker)->delete(route('questions.destroy', $open))->assertRedirect();
        $this->assertSame(1, ProductQuestion::count());
    }

    public function test_questions_and_answers_can_be_reported_but_not_your_own(): void
    {
        $asker = $this->makeBuyer();
        $q = ProductQuestion::factory()->create(['product_id' => $this->product->id, 'user_id' => $asker->id]);
        $a = ProductAnswer::query()->create(['question_id' => $q->id, 'user_id' => $this->seller->id, 'store_id' => $this->seller->store->id, 'body' => 'Answer text here', 'is_seller' => true]);
        $other = $this->makeBuyer();

        $this->actingAs($asker)->post(route('questions.report', $q), ['reason' => 'spam'])->assertForbidden();
        $this->actingAs($other)->post(route('questions.report', $q), ['reason' => 'off_topic'])->assertRedirect()->assertSessionHas('status');
        $this->actingAs($other)->post(route('answers.report', $a), ['reason' => 'fake'])->assertRedirect()->assertSessionHas('status');
        $this->actingAs($this->seller)->post(route('answers.report', $a), ['reason' => 'fake'])->assertForbidden();

        $this->assertSame(2, ContentReport::count());
        $this->assertSame('published', $a->fresh()->status, 'a report never hides anything by itself');
    }

    public function test_a_listing_that_is_off_sale_takes_no_questions(): void
    {
        $this->product->update(['is_active' => false]);

        $this->actingAs($this->makeBuyer())->post(route('questions.store', $this->product), ['body' => 'Is this still made at all?'])->assertNotFound();
    }
}
