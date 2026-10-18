<?php

namespace Tests\Feature\Payments;

use App\Services\Paystack\PaymentGatewayException;
use App\Services\Paystack\PaystackClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsPayments;
use Tests\TestCase;

class PaystackClientTest extends TestCase
{
    use BuildsPayments;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enablePaystack();
    }

    public function test_initialize_sends_exactly_the_documented_payload_with_cents_and_a_bearer_key(): void
    {
        $http = $this->fakeHttp(['api.paystack.co/transaction/initialize' => $this->initializeResponse()]);

        $result = app(PaystackClient::class)->initializeTransaction(
            'buyer@example.test', 24690, 'ZAR', 'EMALL-12-abcdef', 'https://emall.test/orders/12/payment/callback', ['order_id' => 12, 'order_reference' => '#00012'],
        );

        $http->assertSent(function (Request $request) {
            return $request->method() === 'POST'
                && $request->url() === 'https://api.paystack.co/transaction/initialize'
                && $request->header('Authorization') === ['Bearer '.$this->testKey]
                && $request->data() === [
                    'email' => 'buyer@example.test',
                    'amount' => 24690,
                    'currency' => 'ZAR',
                    'reference' => 'EMALL-12-abcdef',
                    'callback_url' => 'https://emall.test/orders/12/payment/callback',
                    'metadata' => json_encode(['order_id' => 12, 'order_reference' => '#00012']),
                ];
        });
        $http->assertSentCount(1);

        $this->assertSame('https://checkout.paystack.com/test-access-code', $result->authorizationUrl);
        $this->assertSame('EMALL-12-abcdef', $result->reference);
        $this->assertSame('test-access-code', $result->accessCode);
    }

    public function test_verify_returns_a_typed_result_in_integer_cents(): void
    {
        $http = $this->fakeHttp(['api.paystack.co/transaction/verify/*' => $this->verifyResponse('EMALL-12-abcdef', 24690)]);

        $result = app(PaystackClient::class)->verifyTransaction('EMALL-12-abcdef');

        $http->assertSent(fn (Request $r) => $r->method() === 'GET' && $r->url() === 'https://api.paystack.co/transaction/verify/EMALL-12-abcdef');
        $this->assertSame('success', $result->status);
        $this->assertTrue($result->isSuccessful());
        $this->assertSame(24690, $result->amountCents);
        $this->assertSame('ZAR', $result->currency);
        $this->assertSame('EMALL-12-abcdef', $result->reference);
        $this->assertSame('4099260516', $result->transactionId);
    }

    public function test_the_reference_is_url_encoded_so_it_cannot_change_the_path(): void
    {
        $http = $this->fakeHttp(['api.paystack.co/*' => $this->verifyResponse('x', 1)]);

        app(PaystackClient::class)->verifyTransaction('../refund?x=1');

        $http->assertSent(fn (Request $r) => ! str_contains($r->url(), '/refund?') && str_starts_with($r->url(), 'https://api.paystack.co/transaction/verify/'));
    }

    public function test_a_refund_request_sends_the_documented_payload(): void
    {
        $http = $this->fakeHttp(['api.paystack.co/refund' => Http::response(['status' => true, 'message' => 'Refund has been queued for processing', 'data' => ['id' => 3018284, 'status' => 'pending']], 200)]);

        $result = app(PaystackClient::class)->createRefund('EMALL-12-abcdef', 5000, 'ZAR', 'Order #00012: item cancelled', 'Order #00012 line cancelled');

        $http->assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->url() === 'https://api.paystack.co/refund'
            && $r->data() === [
                'transaction' => 'EMALL-12-abcdef',
                'amount' => 5000,
                'currency' => 'ZAR',
                'customer_note' => 'Order #00012: item cancelled',
                'merchant_note' => 'Order #00012 line cancelled',
            ]);
        $this->assertSame('3018284', $result->refundId);
        $this->assertSame('pending', $result->status);
    }

    public function test_an_outage_becomes_a_safe_gateway_exception(): void
    {
        $this->fakeHttp(['api.paystack.co/*' => fn () => throw new ConnectionException('cURL error 28: timed out for https://api.paystack.co with '.$this->testKey)]);

        try {
            app(PaystackClient::class)->initializeTransaction('a@b.test', 100, 'ZAR', 'EMALL-1-a', 'https://x.test/cb', []);
            $this->fail('expected PaymentGatewayException');
        } catch (PaymentGatewayException $e) {
            $this->assertStringNotContainsString($this->testKey, $e->getMessage());
            $this->assertStringNotContainsString('cURL', $e->getMessage());
            $this->assertTrue($e->isTemporary());
        }
    }

    public function test_server_errors_and_rejections_are_exceptions_without_the_response_body(): void
    {
        foreach ([[500, true], [401, false], [400, false]] as [$code, $temporary]) {
            $this->fakeHttp(['api.paystack.co/*' => Http::response(['status' => false, 'message' => 'Invalid key sk_test_leaked_in_body'], $code)]);

            try {
                app(PaystackClient::class)->verifyTransaction('EMALL-1-a');
                $this->fail("expected an exception for HTTP {$code}");
            } catch (PaymentGatewayException $e) {
                $this->assertStringNotContainsString('leaked_in_body', $e->getMessage());
                $this->assertSame($temporary, $e->isTemporary(), "HTTP {$code}");
            }
        }
    }

    public function test_a_redirect_is_not_followed_and_is_an_error(): void
    {
        $http = $this->fakeHttp(['api.paystack.co/*' => Http::response('', 302, ['Location' => 'https://evil.test/steal'])]);

        $this->expectException(PaymentGatewayException::class);
        try {
            app(PaystackClient::class)->verifyTransaction('EMALL-1-a');
        } finally {
            $http->assertSentCount(1);
        }
    }

    public function test_a_response_without_the_expected_fields_is_an_exception(): void
    {
        $this->fakeHttp(['api.paystack.co/*' => Http::response(['status' => true, 'data' => ['nonsense' => 1]], 200)]);

        $this->expectException(PaymentGatewayException::class);
        app(PaystackClient::class)->initializeTransaction('a@b.test', 100, 'ZAR', 'EMALL-1-a', 'https://x.test/cb', []);
    }

    public function test_the_buyer_is_only_ever_sent_to_a_paystack_checkout_host(): void
    {
        foreach (['https://evil.example/pay', 'http://checkout.paystack.com/x', 'https://paystack.com.evil.example/x', 'javascript:alert(1)'] as $url) {
            $this->fakeHttp(['api.paystack.co/*' => Http::response(['status' => true, 'data' => ['authorization_url' => $url, 'access_code' => 'a', 'reference' => 'EMALL-1-a']], 200)]);

            try {
                app(PaystackClient::class)->initializeTransaction('a@b.test', 100, 'ZAR', 'EMALL-1-a', 'https://x.test/cb', []);
                $this->fail("{$url} must be refused");
            } catch (PaymentGatewayException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_reply_about_a_different_reference_is_refused(): void
    {
        $this->fakeHttp(['api.paystack.co/*' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/x', 'access_code' => 'a', 'reference' => 'SOMEONE-ELSE']], 200)]);

        $this->expectException(PaymentGatewayException::class);
        app(PaystackClient::class)->initializeTransaction('a@b.test', 100, 'ZAR', 'EMALL-1-a', 'https://x.test/cb', []);
    }

    public function test_a_not_ok_status_flag_in_a_200_is_an_exception(): void
    {
        $this->fakeHttp(['api.paystack.co/*' => Http::response(['status' => false, 'message' => 'nope'], 200)]);

        $this->expectException(PaymentGatewayException::class);
        app(PaystackClient::class)->verifyTransaction('EMALL-1-a');
    }

    public function test_nothing_is_logged_that_contains_the_key_or_the_payload(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = $e->message.' '.json_encode($e->context);
        });

        $this->fakeHttp(['api.paystack.co/*' => Http::response(['status' => false, 'message' => 'bad', 'echo' => 'buyer@example.test'], 400)]);

        try {
            app(PaystackClient::class)->initializeTransaction('buyer@example.test', 24690, 'ZAR', 'EMALL-1-a', 'https://x.test/cb', ['order_id' => 1]);
        } catch (PaymentGatewayException) {
        }

        $this->assertNotEmpty($logged, 'a failed call is logged so operators can see it');
        foreach ($logged as $line) {
            $this->assertStringNotContainsString($this->testKey, $line);
            $this->assertStringNotContainsString('buyer@example.test', $line);
            $this->assertStringNotContainsString('24690', $line);
        }
    }
}
