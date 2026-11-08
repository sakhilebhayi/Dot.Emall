<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Who a behavioural record belongs to, without storing who that is.
 *
 * A signed-in person is represented by a keyed hash of their user id, an anonymous visitor by a keyed hash of a
 * random token kept in their session (the token, unlike the session id, survives the session regeneration that
 * happens at sign-in, which is what lets recently viewed items follow a person across signing in). The hash is an
 * HMAC under the application key, truncated; the raw id never reaches product_views, search_queries or
 * commerce_events. Nothing but the id is needed to recompute a person's hash, so their rows can still be deleted.
 */
final class Actor
{
    public const SESSION_KEY = 'discovery.token';

    private function __construct(
        public readonly ?int $userId,
        public readonly ?string $token,
    ) {}

    public static function forUser(User|int $user): self
    {
        return new self($user instanceof User ? $user->id : $user, null);
    }

    public static function forToken(string $token): self
    {
        return new self(null, $token);
    }

    public static function none(): self
    {
        return new self(null, null);
    }

    /**
     * The signed-in user, else the visitor's anonymous token (created on first use). A request with no session
     * (an API call) is nobody, and nothing about it is recorded.
     */
    public static function forRequest(?Request $request = null): self
    {
        $request ??= request();

        if ($user = $request->user()) {
            return self::forUser($user);
        }

        if (! $request->hasSession()) {
            return self::none();
        }

        $token = $request->session()->get(self::SESSION_KEY);

        if (! is_string($token) || strlen($token) < 20) {
            $token = Str::random(40);
            $request->session()->put(self::SESSION_KEY, $token);
        }

        return self::forToken($token);
    }

    /** The anonymous token of this request's session, if it has one, without creating it. */
    public static function sessionToken(?Request $request = null): ?string
    {
        $request ??= request();

        if (! $request->hasSession()) {
            return null;
        }

        $token = $request->session()->get(self::SESSION_KEY);

        return is_string($token) && $token !== '' ? $token : null;
    }

    public function isIdentifiable(): bool
    {
        return $this->userId !== null || $this->token !== null;
    }

    public function isSignedIn(): bool
    {
        return $this->userId !== null;
    }

    public function hash(): ?string
    {
        return match (true) {
            $this->userId !== null => self::hashFor('u:'.$this->userId),
            $this->token !== null => self::hashFor('s:'.$this->token),
            default => null,
        };
    }

    public static function hashForUser(int $userId): string
    {
        return self::hashFor('u:'.$userId);
    }

    public static function hashForToken(string $token): string
    {
        return self::hashFor('s:'.$token);
    }

    private static function hashFor(string $subject): string
    {
        $key = (string) config('app.key');

        return substr(hash_hmac('sha256', $subject, $key !== '' ? $key : 'dot-emall'), 0, 32);
    }
}
