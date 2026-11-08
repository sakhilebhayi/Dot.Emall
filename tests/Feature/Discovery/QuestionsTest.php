<?php

namespace Tests\Feature\Discovery;

use App\Models\ContentReport;
use App\Models\ProductQuestion;
use App\Models\User;
use App\Notifications\NewQuestionNotification;
use App\Notifications\QuestionAnsweredNotification;
use App\Services\Community\ContentReports;
use App\Services\Community\ModerationQueue;
use App\Services\Community\ProductQuestions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsMarketplace;
use Tests\TestCase;

class QuestionsTest extends TestCase
{
    use BuildsMarketplace;
    use RefreshDatabase;

    private User $seller;

    private User $buyer;

    private $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seller = $this->makeSeller();
        $this->buyer = $this->makeBuyer();
        $this->product = $this->makeListing($this->seller, ['name' => 'Cordless Drill 18V']);
    }

    private function operator(): User
    {
        return User::factory()->create(['is_platform_operator' => true]);
    }

    public function test_a_signed_in_buyer_can_ask_and_the_seller_is_told(): void
    {
        Notification::fake();

        $question = app(ProductQuestions::class)->ask($this->buyer, $this->product, 'Does it come with a battery and charger?');

        $this->assertSame('published', $question->status);
        Notification::assertSentTo($this->seller, NewQuestionNotification::class);
    }

    public function test_one_question_per_person_per_product_per_day(): void
    {
        $questions = app(ProductQuestions::class);
        $questions->ask($this->buyer, $this->product, 'Does it come with a battery and charger?');

        try {
            $questions->ask($this->buyer, $this->product, 'And what is the chuck size please?');
            $this->fail('second question the same day');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('one question', $e->errors()['body'][0]);
        }

        // Another product is fine, and so is tomorrow.
        $questions->ask($this->buyer, $this->makeListing($this->seller), 'Is this available in other sizes?');
        $this->travel(25)->hours();
        $questions->ask($this->buyer, $this->product, 'And what is the chuck size please?');

        $this->assertSame(3, ProductQuestion::count());
    }

    public function test_questions_are_validated_and_the_seller_cannot_ask_their_own(): void
    {
        $questions = app(ProductQuestions::class);

        foreach (['short', str_repeat('x', 501)] as $body) {
            try {
                $questions->ask($this->buyer, $this->product, $body);
                $this->fail('length');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(ValidationException::class);
        $questions->ask($this->seller, $this->product, 'Is this really any good at all?');
    }

    public function test_a_question_with_a_link_or_contact_details_is_held_for_the_operator(): void
    {
        $questions = app(ProductQuestions::class);

        $held = $questions->ask($this->buyer, $this->product, 'Call me on 082 555 0142 and I will pay cash');
        $this->assertSame('held', $held->status);
        $this->assertTrue(app(ModerationQueue::class)->heldQuestions()->contains('id', $held->id));

        $listed = $questions->forProduct($this->product, $this->makeBuyer());
        $this->assertCount(0, $listed, 'a held question is not public');
        $this->assertCount(1, $questions->forProduct($this->product, $this->buyer), 'the asker sees their own waiting question');

        $operator = $this->operator();
        app(ModerationQueue::class)->approve($operator, 'question', $held->id);
        $this->assertCount(1, $questions->forProduct($this->product, $this->makeBuyer()));
    }

    public function test_only_the_stores_own_people_answer_and_the_asker_is_told(): void
    {
        Notification::fake();
        $questions = app(ProductQuestions::class);
        $question = $questions->ask($this->buyer, $this->product, 'Does it come with a battery and charger?');

        $this->assertTrue($this->seller->can('answer', $question));
        $this->assertFalse($this->makeBuyer()->can('answer', $question));

        try {
            $questions->answer($this->makeBuyer(), $question, 'Yes it does.');
            $this->fail('a stranger answered');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        $answer = $questions->answer($this->seller, $question, 'Yes: two batteries and a fast charger.');

        $this->assertTrue($answer->is_seller);
        $this->assertSame(1, $question->fresh()->answers_count);
        Notification::assertSentTo($this->buyer, QuestionAnsweredNotification::class);
    }

    public function test_a_team_member_of_the_store_owner_can_answer(): void
    {
        $staff = $this->makeBuyer();
        $team = $this->seller->ownedTeams()->first() ?? $this->seller->currentTeam;
        DB::table('team_user')->insert(['team_id' => $team->id, 'user_id' => $staff->id, 'role' => 'editor', 'created_at' => now(), 'updated_at' => now()]);
        $question = app(ProductQuestions::class)->ask($this->buyer, $this->product, 'Does it come with a battery and charger?');

        $answer = app(ProductQuestions::class)->answer($staff, $question, 'Yes, it does.');

        $this->assertSame($staff->id, $answer->user_id);
    }

    public function test_a_seller_cannot_delete_or_hide_an_answer_only_the_operator_can(): void
    {
        $questions = app(ProductQuestions::class);
        $question = $questions->ask($this->buyer, $this->product, 'Does it come with a battery and charger?');
        $answer = $questions->answer($this->seller, $question, 'Yes, two batteries.');

        $this->assertFalse($this->seller->can('delete', $answer));
        $this->assertFalse($this->seller->can('moderate', $answer));
        $this->assertTrue($this->operator()->can('moderate', $answer));

        try {
            $questions->hideAnswer($this->seller, $answer);
            $this->fail('seller hid an answer');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        $questions->hideAnswer($this->operator(), $answer);
        $this->assertSame(0, $question->fresh()->answers_count);
    }

    public function test_an_asker_can_withdraw_only_an_unanswered_question(): void
    {
        $questions = app(ProductQuestions::class);
        $question = $questions->ask($this->buyer, $this->product, 'Does it come with a battery and charger?');

        $this->assertTrue($this->buyer->can('delete', $question));
        $this->assertFalse($this->makeBuyer()->can('delete', $question));

        $questions->answer($this->seller, $question, 'Yes.');
        $this->assertFalse($this->buyer->can('delete', $question->fresh()));
    }

    public function test_reporting_goes_to_the_queue_once_per_person_and_never_hides_by_itself(): void
    {
        $question = app(ProductQuestions::class)->ask($this->buyer, $this->product, 'Does it come with a battery and charger?');
        $reports = app(ContentReports::class);
        $reporter = $this->makeBuyer();

        $reports->report($reporter, 'question', $question->id, 'spam', 'looks like an advert');

        try {
            $reports->report($reporter, 'question', $question->id, 'spam');
            $this->fail('duplicate');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        $reports->report($this->makeBuyer(), 'question', $question->id, 'off_topic');

        $this->assertSame('published', $question->fresh()->status, 'reports alone never hide anything');
        $queue = app(ModerationQueue::class)->reported();
        $this->assertSame(2, $queue->first()['reports']);
        $this->assertEqualsCanonicalizing(['spam', 'off_topic'], $queue->first()['reasons']);
    }

    public function test_you_cannot_report_your_own_post_or_something_that_is_not_there(): void
    {
        $question = app(ProductQuestions::class)->ask($this->buyer, $this->product, 'Does it come with a battery and charger?');

        foreach ([['question', $question->id, $this->buyer], ['question', 9999, $this->makeBuyer()]] as [$type, $id, $who]) {
            try {
                app(ContentReports::class)->report($who, $type, $id, 'spam');
                $this->fail('refused');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, ContentReport::count());
    }

    public function test_the_operator_resolves_a_report_by_removing_or_dismissing_with_an_audit_trail(): void
    {
        $question = app(ProductQuestions::class)->ask($this->buyer, $this->product, 'Does it come with a battery and charger?');
        app(ContentReports::class)->report($this->makeBuyer(), 'question', $question->id, 'spam');
        $operator = $this->operator();
        $queue = app(ModerationQueue::class);

        try {
            $queue->resolve($this->seller, 'question', $question->id, 'remove');
            $this->fail('seller moderated');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        $queue->resolve($operator, 'question', $question->id, 'remove', 'advert');

        $this->assertSame('hidden', $question->fresh()->status);
        $report = ContentReport::first();
        $this->assertSame('actioned', $report->status);
        $this->assertSame($operator->id, $report->handled_by);
        $this->assertNotNull($report->handled_at);
        $this->assertCount(0, $queue->reported());
    }
}
