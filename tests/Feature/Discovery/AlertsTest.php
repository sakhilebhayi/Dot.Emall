<?php

namespace Tests\Feature\Discovery;

use App\Models\ProductAlert;
use App\Models\SavedSearch;
use App\Models\User;
use App\Notifications\BackInStockNotification;
use App\Notifications\PriceDropNotification;
use App\Notifications\SavedSearchDigestNotification;
use App\Services\Discovery\AlertEvaluator;
use App\Services\Discovery\ProductAlerts;
use App\Services\Discovery\SavedSearches;
use App\Services\Notifications\NotificationPreferences;
use App\Services\Search\SearchRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsCatalogue;
use Tests\Concerns\BuildsMarketplace;
use Tests\TestCase;

class AlertsTest extends TestCase
{
    use BuildsCatalogue;
    use BuildsMarketplace;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCatalogue();
        Notification::fake();
    }

    public function test_back_in_stock_is_sent_once_when_stock_returns(): void
    {
        $user = $this->makeBuyer();
        $product = $this->makeListing($this->makeSeller(), ['stock' => 0]);
        app(ProductAlerts::class)->subscribe($user, $product, ProductAlert::BACK_IN_STOCK);
        $evaluator = app(AlertEvaluator::class);

        $this->assertSame(0, $evaluator->backInStock());
        Notification::assertNothingSent();

        $product->update(['stock' => 4]);

        $this->assertSame(1, $evaluator->backInStock());
        $this->assertSame(0, $evaluator->backInStock(), 'a second run sends nothing');
        $this->assertSame(0, app(AlertEvaluator::class)->run()['back_in_stock']);
        Notification::assertSentToTimes($user, BackInStockNotification::class, 1);
        $this->assertFalse(ProductAlert::first()->active);
    }

    public function test_a_back_in_stock_alert_is_refused_when_the_product_is_in_stock(): void
    {
        $this->expectException(ValidationException::class);
        app(ProductAlerts::class)->subscribe($this->makeBuyer(), $this->makeListing($this->makeSeller(), ['stock' => 3]), ProductAlert::BACK_IN_STOCK);
    }

    public function test_a_price_drop_notice_is_sent_once_per_new_low(): void
    {
        $user = $this->makeBuyer();
        $product = $this->makeListing($this->makeSeller(), ['price' => 1000.00]);
        app(ProductAlerts::class)->subscribe($user, $product, ProductAlert::PRICE_DROP);
        $evaluator = app(AlertEvaluator::class);

        $this->assertSame(0, $evaluator->priceDrops(), 'no change, no notice');

        $product->update(['price' => 900.00]);
        $this->assertSame(1, $evaluator->priceDrops());
        $this->assertSame(0, $evaluator->priceDrops());

        $product->update(['price' => 950.00]);
        $this->assertSame(0, $evaluator->priceDrops(), 'a rise and a smaller drop is not a new low');

        $product->update(['price' => 850.00]);
        $this->assertSame(1, $evaluator->priceDrops());

        Notification::assertSentToTimes($user, PriceDropNotification::class, 2);
    }

    public function test_a_target_price_waits_for_the_target(): void
    {
        $user = $this->makeBuyer();
        $product = $this->makeListing($this->makeSeller(), ['price' => 1000.00]);
        app(ProductAlerts::class)->subscribe($user, $product, ProductAlert::PRICE_DROP, 800.0);
        $evaluator = app(AlertEvaluator::class);

        $product->update(['price' => 900.00]);
        $this->assertSame(0, $evaluator->priceDrops());

        $product->update(['price' => 800.00]);
        $this->assertSame(1, $evaluator->priceDrops());
    }

    public function test_a_target_at_or_above_the_current_price_is_refused(): void
    {
        $this->expectException(ValidationException::class);
        app(ProductAlerts::class)->subscribe($this->makeBuyer(), $this->makeListing($this->makeSeller(), ['price' => 100.0]), ProductAlert::PRICE_DROP, 100.0);
    }

    public function test_the_notice_quotes_the_lowest_price_from_the_one_price_history(): void
    {
        $user = $this->makeBuyer();
        $this->travelTo(now()->subDays(12));
        $product = $this->makeListing($this->makeSeller(), ['price' => 700.00]);
        $this->travelTo(now()->addDays(10));
        $product->update(['price' => 1000.00]);
        $this->travelBack();
        app(ProductAlerts::class)->subscribe($user, $product->refresh(), ProductAlert::PRICE_DROP);
        $product->update(['price' => 900.00]);

        app(AlertEvaluator::class)->priceDrops();

        Notification::assertSentTo($user, PriceDropNotification::class, function (PriceDropNotification $n) use ($user) {
            return $n->lowest30 === 700.0 && str_contains($n->toDatabase($user)['message'], 'lowest price in the last 30 days was R700.00');
        });
    }

    public function test_a_price_from_before_the_window_that_still_held_at_its_start_counts(): void
    {
        $this->travelTo(now()->subDays(90));
        $product = $this->makeListing($this->makeSeller(), ['price' => 500.00]);
        $this->travelTo(now()->addDays(40));
        $product->update(['price' => 800.00]);
        $this->travelBack();

        $this->assertSame(800.0, app(ProductAlerts::class)->lowestRecentPrice($product->refresh()), 'R500 ended 50 days ago, outside the 30 day window');

        $this->travelTo(now()->subDays(60));
        $other = $this->makeListing($this->makeSeller(), ['price' => 500.00]);
        $this->travelTo(now()->addDays(45));
        $other->update(['price' => 800.00]);
        $this->travelBack();

        $this->assertSame(500.0, app(ProductAlerts::class)->lowestRecentPrice($other->refresh()), 'R500 held until 15 days ago, so it was in effect inside the window');
    }

    public function test_with_no_lower_recorded_price_nothing_is_invented(): void
    {
        $user = $this->makeBuyer();
        $product = $this->makeListing($this->makeSeller(), ['price' => 1000.00]);
        app(ProductAlerts::class)->subscribe($user, $product, ProductAlert::PRICE_DROP);
        $product->update(['price' => 900.00]);

        app(AlertEvaluator::class)->priceDrops();

        Notification::assertSentTo($user, PriceDropNotification::class, fn ($n) => $n->lowest30 === 900.0 && ! str_contains($n->toDatabase($user)['message'], 'lowest'), 'today\'s price is the lowest on record, so the notice makes no claim about an earlier low');
    }

    public function test_alerts_use_the_one_preferences_service(): void
    {
        $user = User::factory()->create();
        $notification = new BackInStockNotification($this->makeListing($this->makeSeller()));
        $prefs = app(NotificationPreferences::class);

        $this->assertSame(['database'], $notification->via($user), 'alerts are in the app by default; e-mail is opt-in');

        $prefs->set($user, NotificationPreferences::ALERTS, mail: true, inApp: true);
        $this->assertSame(['database', 'mail'], (new BackInStockNotification($notification->product))->via($user));

        $prefs->set($user, NotificationPreferences::ALERTS, mail: false, inApp: false);
        $this->assertSame([], (new BackInStockNotification($notification->product))->via($user));
    }

    public function test_a_person_who_turned_alerts_off_is_not_sent_one_but_the_alert_is_still_spent(): void
    {
        $user = $this->makeBuyer();
        $product = $this->makeListing($this->makeSeller(), ['stock' => 0]);
        app(ProductAlerts::class)->subscribe($user, $product, ProductAlert::BACK_IN_STOCK);
        app(NotificationPreferences::class)->set($user, NotificationPreferences::ALERTS, mail: false, inApp: false);
        $product->update(['stock' => 3]);

        $this->assertSame(0, app(AlertEvaluator::class)->backInStock());
        Notification::assertNothingSent();
        $this->assertFalse(ProductAlert::first()->active);
    }

    public function test_the_alert_e_mail_is_built_on_the_shared_layout_from_the_same_payload_as_the_in_app_row(): void
    {
        $user = User::factory()->create();
        $notice = new BackInStockNotification($this->makeListing($this->makeSeller(), ['name' => 'Camping Stove Deluxe']));

        $mail = $notice->toMail($user);

        $this->assertSame($notice->toDatabase($user)['title'], $mail->subject);
        $this->assertSame('Dot.Emall', $mail->salutation);
        $this->assertSame('View product', $mail->actionText);
        $this->assertStringContainsString('Camping Stove Deluxe', implode(' ', $mail->introLines));
        $this->assertStringContainsString('This alert is now finished', implode(' ', $mail->outroLines));
    }

    public function test_a_saved_search_digest_goes_out_on_schedule_for_new_matches_only(): void
    {
        $user = $this->makeBuyer();
        $store = $this->store();
        $this->listing($store, 'Garden Hose 20m', 250.0);
        $this->reindex();
        $saved = app(SavedSearches::class)->save($user, 'Hoses', new SearchRequest(text: 'hose'), 'daily');
        $evaluator = app(AlertEvaluator::class);

        $this->assertSame(0, $evaluator->savedSearches(), 'nothing new yet');

        $this->listing($store, 'Heavy Duty Hose 50m', 400.0);
        $this->reindex();

        $this->assertSame(1, $evaluator->savedSearches());
        $this->assertSame(0, $evaluator->savedSearches(), 'the same matches are not reported twice');
        Notification::assertSentToTimes($user, SavedSearchDigestNotification::class, 1);

        $this->listing($store, 'Hose Reel', 600.0);
        $this->reindex();
        $this->assertSame(0, $evaluator->savedSearches(), 'daily means at most once a day');

        $this->travel(25)->hours();
        $this->assertSame(1, $evaluator->savedSearches());
    }

    public function test_a_saved_search_with_notifications_off_never_sends(): void
    {
        $user = $this->makeBuyer();
        $store = $this->store();
        app(SavedSearches::class)->save($user, 'Hoses', new SearchRequest(text: 'hose'), 'off');
        $this->listing($store, 'Garden Hose', 250.0);
        $this->reindex();

        $this->assertSame(0, app(AlertEvaluator::class)->savedSearches());
        $this->assertSame('off', SavedSearch::first()->notify_frequency);
    }

    public function test_the_command_runs(): void
    {
        $this->artisan('emall:alerts-evaluate')->assertSuccessful();
    }
}
