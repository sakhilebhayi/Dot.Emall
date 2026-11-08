<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderGroup;
use App\Models\OrderMessage;
use App\Models\User;
use App\Notifications\OrderMessageNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Contact the seller of a parcel, or report a problem to the Emall team, from an order. The buyer is always the order's own buyer (the
 * controller has checked the account or the guest link). The message is stored, then the seller's people (the owner and the people
 * on the owner's team) or the operators are notified; a failed notice never loses the message. At most 5 a day per order.
 */
class OrderMessages
{
    public const DAILY_LIMIT = 5;

    /** @throws ValidationException */
    public function toSeller(Order $order, OrderGroup $group, ?User $buyer, string $body): OrderMessage
    {
        abort_unless($group->order_id === $order->id, 404);

        return $this->store($order, $group, OrderMessage::SELLER, $buyer, $body, $this->sellerPeople($group));
    }

    /** @throws ValidationException */
    public function reportProblem(Order $order, ?User $buyer, string $body): OrderMessage
    {
        $operators = User::query()->where('is_platform_operator', true)->get()->all();

        return $this->store($order, null, OrderMessage::OPERATOR, $buyer, $body, $operators);
    }

    /** @return list<User> */
    private function sellerPeople(OrderGroup $group): array
    {
        $ids = DB::table('team_user')->join('teams', 'teams.id', '=', 'team_user.team_id')->where('teams.user_id', $group->seller_id)->pluck('team_user.user_id')->push($group->seller_id)->unique()->filter();

        return User::query()->whereIn('id', $ids)->get()->all();
    }

    /** @param  list<User>  $people */
    private function store(Order $order, ?OrderGroup $group, string $kind, ?User $buyer, string $body, array $people): OrderMessage
    {
        $body = trim($body);

        if (mb_strlen($body) < 10) {
            throw ValidationException::withMessages(['body' => 'Write a little more, so the reader knows what you need (at least 10 characters).']);
        }

        if (mb_strlen($body) > 2000) {
            throw ValidationException::withMessages(['body' => 'Keep it under 2000 characters.']);
        }

        $key = "order-message:{$order->id}:{$kind}";

        if (RateLimiter::tooManyAttempts($key, self::DAILY_LIMIT)) {
            throw ValidationException::withMessages(['body' => 'You have sent '.self::DAILY_LIMIT.' messages about this order today. Please wait for an answer before sending more.']);
        }

        RateLimiter::hit($key, 86400);

        $message = OrderMessage::create([
            'order_id' => $order->id, 'order_group_id' => $group?->id, 'kind' => $kind, 'sender_id' => $buyer?->id,
            'sender_name' => mb_substr($order->shipping_name ?: ($buyer?->name ?? 'A customer'), 0, 120),
            'sender_email' => (string) $order->buyerEmail(), 'body' => $body,
        ]);

        foreach ($people as $person) {
            try {
                $person->notify(new OrderMessageNotification($message->id, $this->urlFor($kind, $order)));
            } catch (\Throwable $e) {
                Log::warning('An order message notice could not be sent.', ['message_id' => $message->id, 'error' => class_basename($e)]);
            }
        }

        return $message;
    }

    private function urlFor(string $kind, Order $order): string
    {
        return route('orders.show', $order);
    }
}
