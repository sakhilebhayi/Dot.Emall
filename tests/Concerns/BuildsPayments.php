<?php

namespace Tests\Concerns;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\PaymentService;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

/**
 * Fixtures for the Paystack tests. Nothing here talks to the network: every
 * request goes through Http::fake via fakeHttp(), which also refuses any
 * request that was not given an answer (a test that forgot to fake an
 * endpoint fails instead of calling Paystack).
 */
trait BuildsPayments
{
    protected string $testKey = 'sk_test_unit_test_key_0123456789';

    protected function enablePaystack(?string $key = null, array $extra = []): void
    {
        config(array_merge([
            'paystack.secret_key' => $key ?? $this->testKey,
            'paystack.public_key' => 'pk_test_unit_public_0123456789',
            'paystack.base_url' => 'https://api.paystack.co',
            'paystack.allow_live' => false,
            'paystack.currency' => 'ZAR',
            'paystack.unpaid_window_minutes' => 60,
            'paystack.attempt_ttl_minutes' => 30,
        ], $extra));
    }

    /**
     * @param  array<string, mixed>  $routes  URL pattern => response / callback / sequence
     */
    protected function fakeHttp(array $routes = []): Factory
    {
        $factory = new Factory;
        $factory->preventStrayRequests();
        $factory->fake($routes);
        Http::swap($factory);

        return $factory;
    }

    protected function initializeResponse(string $reference = 'ignored', string $url = 'https://checkout.paystack.com/test-access-code'): callable
    {
        return function ($request) use ($url) {
            return Http::response(['status' => true, 'message' => 'Authorization URL created', 'data' => [
                'authorization_url' => $url,
                'access_code' => 'test-access-code',
                'reference' => $request->data()['reference'],
            ]], 200);
        };
    }

    protected function verifyResponse(string $reference, int $amountCents, string $status = 'success', array $overrides = []): PromiseInterface
    {
        return Http::response(['status' => true, 'message' => 'Verification successful', 'data' => array_merge([
            'id' => 4099260516,
            'status' => $status,
            'reference' => $reference,
            'amount' => $amountCents,
            'currency' => 'ZAR',
            'paid_at' => '2026-10-18T12:00:00.000Z',
            'gateway_response' => 'Successful',
            'channel' => 'card',
        ], $overrides)], 200);
    }

    protected function enablePaystackAndPlace(User $buyer, array $lines, array $input = []): Order
    {
        $this->enablePaystack();

        return $this->placeOrder($buyer, $lines, $input);
    }

    /**
     * Starts a payment attempt for the order through the real service, with
     * Paystack's initialize endpoint faked.
     */
    protected function startAttempt(Order $order): Payment
    {
        $this->fakeHttp(['api.paystack.co/transaction/initialize' => $this->initializeResponse('x')]);

        return app(PaymentService::class)->startPayment($order->fresh());
    }

    /**
     * Drives an order to PAID the way production does: an attempt, then the
     * charge.success path.
     */
    protected function payOrder(Order $order, string $transactionId = '4099260516'): Payment
    {
        $payment = $this->startAttempt($order);

        app(PaymentService::class)->recordSuccessfulCharge(
            $payment->reference, $payment->amount_cents, $payment->currency, $transactionId, [], 'webhook',
        );

        return $payment->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    protected function chargeSuccessPayload(Payment $payment, array $dataOverrides = [], string $event = 'charge.success'): array
    {
        return ['event' => $event, 'data' => array_merge([
            'id' => 4099260516,
            'status' => 'success',
            'reference' => $payment->reference,
            'amount' => $payment->amount_cents,
            'currency' => $payment->currency,
            'paid_at' => '2026-10-18T12:00:00.000Z',
            'gateway_response' => 'Successful',
            'channel' => 'card',
        ], $dataOverrides)];
    }

    protected function sign(string $rawBody, ?string $key = null): string
    {
        return hash_hmac('sha512', $rawBody, $key ?? $this->testKey);
    }

    protected function postWebhook(array|string $payload, ?string $signature = null, bool $sign = true)
    {
        $raw = is_array($payload) ? json_encode($payload) : $payload;
        $headers = ['CONTENT_TYPE' => 'application/json'];

        if ($sign) {
            $headers['HTTP_X_PAYSTACK_SIGNATURE'] = $signature ?? $this->sign($raw);
        }

        return $this->call('POST', '/webhooks/paystack', [], [], [], $headers, $raw);
    }
}
