<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A buyer's message about an order: to one seller (kind "seller") or to the Emall team (kind "operator"). */
class OrderMessage extends Model
{
    public const SELLER = 'seller';

    public const OPERATOR = 'operator';

    protected $fillable = ['order_id', 'order_group_id', 'kind', 'sender_id', 'sender_name', 'sender_email', 'body', 'handled_at', 'handled_by'];

    protected $casts = ['handled_at' => 'datetime'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(OrderGroup::class, 'order_group_id');
    }
}
