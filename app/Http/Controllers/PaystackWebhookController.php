<?php

namespace App\Http\Controllers;

use App\Services\Paystack\PaymentGatewayException;
use App\Services\Paystack\PaystackConfig;
use App\Services\PaystackWebhookHandler;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * POST /webhooks/paystack. Order of operations matters:
 *
 *   1. signature: header `x-paystack-signature` must equal the lowercase hex
 *      HMAC-SHA512 of the RAW request body keyed with the secret key,
 *      compared with hash_equals. Before anything is parsed. Any failure
 *      (no header, wrong value, no key, a refused live key) is the same
 *      generic 401: it never says which.
 *   2. only then is the body parsed; JSON that is not an object with an
 *      `event` string is a 400.
 *   3. the handler acts, and the answer is 200 whenever the event was
 *      understood, matched or not, so Paystack does not retry it for days.
 */
class PaystackWebhookController extends Controller
{
    public function __invoke(Request $request, PaystackConfig $config, PaystackWebhookHandler $handler): Response
    {
        $raw = $request->getContent();
        $given = strtolower(trim((string) $request->header('x-paystack-signature')));

        try {
            $key = $config->secretKey();
        } catch (PaymentGatewayException) {
            return $this->unauthorized();
        }

        if ($given === '' || ! hash_equals(hash_hmac('sha512', $raw, $key), $given)) {
            return $this->unauthorized();
        }

        $payload = json_decode($raw, true, 32);

        if (! is_array($payload) || ! is_string($payload['event'] ?? null) || $payload['event'] === '') {
            return response('Bad request.', 400);
        }

        $handler->handle($payload);

        return response('OK', 200);
    }

    private function unauthorized(): Response
    {
        return response('Unauthorized.', 401);
    }
}
