<?php

namespace App\Services\Shipping;

use App\Models\Address;

/** Where a parcel is going, as far as shipping rules care: country, province, postal code. */
final class Region
{
    public function __construct(
        public readonly string $country = 'ZA',
        public readonly ?string $province = null,
        public readonly ?string $postalCode = null,
    ) {}

    /**
     * @param  Address|array<string, mixed>|null  $address  an address row, an order snapshot, or form fields
     */
    public static function from(Address|array|null $address): ?self
    {
        if ($address === null) {
            return null;
        }

        $data = $address instanceof Address ? $address->snapshot() : $address;

        $country = strtoupper(trim((string) ($data['country'] ?? 'ZA'))) ?: 'ZA';
        $province = trim((string) ($data['province'] ?? '')) ?: null;
        $postal = trim((string) ($data['postal_code'] ?? '')) ?: null;

        return new self($country, $province, $postal);
    }

    public function toArray(): array
    {
        return ['country' => $this->country, 'province' => $this->province, 'postal_code' => $this->postalCode];
    }
}
