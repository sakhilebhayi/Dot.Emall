<?php

namespace App\Services\Notify;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells Dot.Notify "this happened, tell this person" (POST {NOTIFY_INGEST_URL}). Follows the pattern of the Dot
 * platforms that already use it: HMAC-SHA256 over "{timestamp}.{raw body}" sent as `sha256=<hex>` in
 * X-InfoDot-Signature with the unix time in X-InfoDot-Timestamp; a 5 second timeout; never throws into the caller;
 * and with no URL or secret it does nothing at all. Routed by the recipient's InfoDot company (their current or
 * personal team's infodot_team_uuid); a person without one is skipped. Logs carry the event name, never an address.
 */
class NotifyClient
{
    public function enabled(): bool
    {
        return filled(config('emall.notify.url')) && filled(config('emall.notify.secret'));
    }

    /**
     * @param  array<string, scalar|null>  $data
     */
    public function send(User $recipient, string $event, string $bucket, array $data): bool
    {
        if (! $this->enabled() || blank($recipient->email)) {
            return false;
        }

        try {
            $uuid = $recipient->currentTeam?->infodot_team_uuid
                ?? $recipient->allTeams()->first(fn ($team) => filled($team->infodot_team_uuid))?->infodot_team_uuid;

            if (blank($uuid)) {
                return false;
            }

            $body = json_encode([
                'platform' => (string) config('emall.notify.platform', 'emall'),
                'company_uuid' => $uuid,
                'event' => $event,
                'recipient' => ['email' => $recipient->email],
                'data' => $data,
                'idempotency_key' => substr($event.':'.sha1($recipient->email).':'.$bucket, 0, 120),
            ], JSON_THROW_ON_ERROR);

            $timestamp = (string) time();
            $signature = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, (string) config('emall.notify.secret'));

            $response = Http::timeout(5)->withHeaders([
                'X-InfoDot-Signature' => $signature,
                'X-InfoDot-Timestamp' => $timestamp,
                'Content-Type' => 'application/json',
            ])->withBody($body, 'application/json')->post((string) config('emall.notify.url'));

            if (! $response->successful()) {
                Log::warning('Dot.Notify refused an event.', ['event' => $event, 'status' => $response->status()]);
            }

            return $response->successful();
        } catch (Throwable $e) {
            Log::warning('Dot.Notify could not be reached.', ['event' => $event, 'error' => class_basename($e)]);

            return false;
        }
    }
}
