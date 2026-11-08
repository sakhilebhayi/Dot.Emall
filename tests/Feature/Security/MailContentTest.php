<?php

namespace Tests\Feature\Security;

use App\Models\OrderMessage;
use App\Notifications\OrderMessageNotification;
use App\Services\Orders\OrderMessages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Tests\Concerns\BuildsCommerce;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\BuildsPayments;
use Tests\TestCase;

/**
 * R-43 security pass. Words a customer typed are put into e-mails that sellers and the Emall team receive, from Dot.Emall's own address and
 * layout. The mail layout turns Markdown into HTML, so a message that is a Markdown link or image would come out as a real link or a
 * tracking pixel inside a trusted e-mail. A customer's words are text, never mark-up.
 */
class MailContentTest extends TestCase
{
    use BuildsCommerce;
    use BuildsMarketplace;
    use BuildsPayments;
    use RefreshDatabase;

    private function render(string $body): string
    {
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        $this->withDelivery($seller, ['price' => 40]);
        $this->enablePaystack();
        $order = $this->placeOrder($buyer, [[$this->makeListing($seller, ['price' => 100, 'stock' => 5]), 1]]);
        $group = $order->groups()->first();

        $message = app(OrderMessages::class)->toSeller($order, $group, $buyer, $body);
        $this->assertInstanceOf(OrderMessage::class, $message);

        $mail = (new OrderMessageNotification($message->id, 'https://emall.test/orders/1'))->toMail(new AnonymousNotifiable);

        return (string) $mail->render();
    }

    public function test_a_customers_markdown_link_is_not_turned_into_a_link_in_the_sellers_email(): void
    {
        $html = $this->render('Please confirm your payout at [Dot.Emall seller security](https://evil.example/login) today.');

        $this->assertStringNotContainsString('href="https://evil.example', $html);
        $this->assertStringContainsString('evil.example/login', $html, 'the words are still there, as text');
    }

    public function test_a_customers_markdown_image_is_not_loaded_by_the_sellers_email_client(): void
    {
        $html = $this->render('Hi ![](https://tracker.example/pixel.gif?seller=1) there');

        $this->assertDoesNotMatchRegularExpression('/<img[^>]+tracker\.example/', $html);
    }

    public function test_a_bare_address_is_not_made_clickable_and_emphasis_is_not_applied(): void
    {
        $html = $this->render('Ring me on https://evil.example/pay or **act now** or # shout');

        $this->assertDoesNotMatchRegularExpression('/<a [^>]*href="https:\/\/evil\.example/', $html);
        $this->assertStringNotContainsString('<strong>act now</strong>', $html);
        $this->assertStringContainsString('**act now**', $html, 'shown exactly as typed');
    }

    public function test_the_buyers_own_link_to_the_order_is_still_a_button(): void
    {
        $html = $this->render('Where is my order?');

        $this->assertStringContainsString('href="https://emall.test/orders/1"', $html);
    }
}
