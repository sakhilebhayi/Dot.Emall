<?php

namespace App\Models;

use Database\Factories\AddressFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Address extends Model
{
    /** @use HasFactory<AddressFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id', 'label', 'recipient_name', 'phone', 'line1', 'line2', 'suburb', 'city', 'province',
        'postal_code', 'country', 'instructions', 'is_default_shipping', 'is_default_billing',
    ];

    protected $casts = ['is_default_shipping' => 'boolean', 'is_default_billing' => 'boolean'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The immutable copy stored on an order: the address as it was, whatever the book says later.
     *
     * @return array<string, ?string>
     */
    public function snapshot(): array
    {
        return [
            'recipient_name' => $this->recipient_name,
            'phone' => $this->phone,
            'line1' => $this->line1,
            'line2' => $this->line2,
            'suburb' => $this->suburb,
            'city' => $this->city,
            'province' => $this->province,
            'postal_code' => $this->postal_code,
            'country' => $this->country ?: 'ZA',
            'instructions' => $this->instructions,
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public static function oneLine(array $snapshot): string
    {
        return collect([
            $snapshot['line1'] ?? null, $snapshot['line2'] ?? null, $snapshot['suburb'] ?? null,
            $snapshot['city'] ?? null, $snapshot['province'] ?? null, $snapshot['postal_code'] ?? null,
        ])->filter(fn ($part) => filled($part))->implode(', ');
    }
}
