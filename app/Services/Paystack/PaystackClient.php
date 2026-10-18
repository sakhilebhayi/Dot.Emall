<?php

namespace App\Services\Paystack;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The only code that talks to Paystack. Three calls (endpoints and fields
 * as in Paystack's API reference):
 *
 *   POST /transaction/initialize   -> initializeTransaction()
 *   GET  /transaction/verify/:ref  -> verifyTransaction()
 *   POST /refund                   -> createRefund()
 *
 * Amounts are integer subunits (cents) in and out. Hard rules: the key comes
 * only from PaystackConfig (which refuses a live key unless allowed), the
 * base URL is https, 10 second timeout, redirects are never followed, nothing
 * is retried (a retried initialize or refund could double up), and nothing is
 * logged except the endpoint name and HTTP status: no key, no request or
 * response body. Failures surface as PaymentGatewayException with a message
 * safe to show.
 */
class PaystackClient
{
    public function __construct(private readonly PaystackConfig $config) {}

    /**
     * @param  array<string, mixed>  $metadata
     *
     * @throws PaymentGatewayException
     */
    public function initializeTransaction(string $email, int $amountCents, string $currency, string $reference, string $callbackUrl, array $metadata = []): InitializedTransaction
    {
        $data = $this->send('transaction/initialize', fn (PendingRequest $http) => $http->post('transaction/initialize', [
            'email' => $email,
            'amount' => $amountCents,
            'currency' => $currency,
            'reference' => $reference,
            'callback_url' => $callbackUrl,
            // Paystack documents metadata as a stringified JSON object.
            'metadata' => json_encode($metadata),
        ]));

        $url = $data['authorization_url'] ?? null;

        // The reply must be about the reference we sent, and the buyer is only
        // ever sent to a Paystack checkout host, so a tampered base URL or
        // reply cannot turn "Pay now" into a redirect to someone else's page.
        if (! is_string($url) || ! $this->isPaystackCheckoutUrl($url) || ! is_string($data['access_code'] ?? null) || ($data['reference'] ?? null) !== $reference) {
            Log::warning('Paystack request failed', ['endpoint' => 'transaction/initialize', 'reason' => 'unexpected_reply']);

            throw PaymentGatewayException::malformed();
        }

        return new InitializedTransaction($url, $data['access_code'], $data['reference']);
    }

    /**
     * @throws PaymentGatewayException
     */
    public function verifyTransaction(string $reference): VerifiedTransaction
    {
        $data = $this->send('transaction/verify', fn (PendingRequest $http) => $http->get('transaction/verify/'.rawurlencode($reference)));

        if (! is_string($data['status'] ?? null)
            || ! is_numeric($data['amount'] ?? null)
            || ! is_string($data['currency'] ?? null)
            || ! is_string($data['reference'] ?? null)
            || ! is_scalar($data['id'] ?? null)) {
            throw PaymentGatewayException::malformed();
        }

        return new VerifiedTransaction(
            status: $data['status'],
            amountCents: (int) $data['amount'],
            currency: strtoupper($data['currency']),
            reference: $data['reference'],
            transactionId: (string) $data['id'],
            paidAt: is_string($data['paid_at'] ?? null) ? $data['paid_at'] : null,
            gatewayResponse: is_string($data['gateway_response'] ?? null) ? $data['gateway_response'] : null,
            channel: is_string($data['channel'] ?? null) ? $data['channel'] : null,
        );
    }

    /**
     * Asks Paystack to refund $amountCents of the transaction (a partial
     * refund when smaller than the original). The request is only queued:
     * the money has NOT moved until Paystack reports refund.processed.
     *
     * @throws PaymentGatewayException
     */
    public function createRefund(string $transactionReference, int $amountCents, string $currency, ?string $customerNote = null, ?string $merchantNote = null): CreatedRefund
    {
        $payload = [
            'transaction' => $transactionReference,
            'amount' => $amountCents,
            'currency' => $currency,
        ];

        if ($customerNote !== null) {
            $payload['customer_note'] = $customerNote;
        }

        if ($merchantNote !== null) {
            $payload['merchant_note'] = $merchantNote;
        }

        $data = $this->send('refund', fn (PendingRequest $http) => $http->post('refund', $payload));

        if (! is_scalar($data['id'] ?? null) || ! is_string($data['status'] ?? null)) {
            throw PaymentGatewayException::malformed();
        }

        return new CreatedRefund((string) $data['id'], $data['status']);
    }

    private function isPaystackCheckoutUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));

        return ($parts['scheme'] ?? '') === 'https'
            && ($host === 'paystack.com' || str_ends_with($host, '.paystack.com') || $host === 'paystack.co' || str_ends_with($host, '.paystack.co'));
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     * @return array<string, mixed> Paystack's `data` object
     *
     * @throws PaymentGatewayException
     */
    private function send(string $endpoint, callable $call): array
    {
        // Throws, before any request exists, if there is no key or it is refused.
        $key = $this->config->secretKey();

        try {
            $response = $call(
                Http::withToken($key)
                    ->baseUrl($this->config->baseUrl())
                    ->acceptJson()
                    ->asJson()
                    ->timeout(10)
                    ->connectTimeout(5)
                    ->withoutRedirecting()
            );
        } catch (ConnectionException) {
            Log::warning('Paystack request failed', ['endpoint' => $endpoint, 'reason' => 'connection']);

            throw PaymentGatewayException::unavailable();
        }

        if (! $response->successful()) {
            Log::warning('Paystack request failed', ['endpoint' => $endpoint, 'http_status' => $response->status()]);

            throw $response->serverError() || $response->status() === 429
                ? PaymentGatewayException::serverError($response->status())
                : PaymentGatewayException::rejected($response->status());
        }

        $json = $response->json();

        if (! is_array($json) || ($json['status'] ?? null) !== true || ! is_array($json['data'] ?? null)) {
            Log::warning('Paystack request failed', ['endpoint' => $endpoint, 'reason' => 'unexpected_reply']);

            throw PaymentGatewayException::malformed();
        }

        return $json['data'];
    }
}
