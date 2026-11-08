<?php

namespace App\Services\Storefront;

use App\Models\User;
use App\Services\Addresses\AddressBook;
use App\Services\Addresses\AddressRules;
use App\Services\Shipping\Region;
use Illuminate\Http\Request;

/**
 * Where the visitor wants things delivered, as far as a price on a product page is concerned: a province and, optionally,
 * a postal code. It comes from what the visitor chose on the page (kept in their session, nothing else) or, for a signed-in
 * person who chose nothing, from their default shipping address. With neither, the region is unknown and the page says
 * so instead of guessing a delivery cost.
 */
final class DeliveryRegion
{
    public const SESSION_KEY = 'emall.delivery_region';

    /**
     * @return array{province: string, postal_code: ?string, source: string, label: string}|null
     */
    public static function current(?Request $request = null, ?User $user = null): ?array
    {
        $request ??= request();
        $chosen = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;

        if (is_array($chosen) && in_array($chosen['province'] ?? null, AddressRules::PROVINCES, true)) {
            $postal = self::cleanPostal($chosen['postal_code'] ?? null);

            return ['province' => $chosen['province'], 'postal_code' => $postal, 'source' => 'chosen', 'label' => trim($chosen['province'].' '.$postal)];
        }

        $user ??= $request->user();

        if ($user !== null) {
            $address = app(AddressBook::class)->defaultShipping($user);

            if ($address !== null && in_array($address->province, AddressRules::PROVINCES, true) && strtoupper((string) $address->country) === 'ZA') {
                $postal = self::cleanPostal($address->postal_code);

                return ['province' => $address->province, 'postal_code' => $postal, 'source' => 'address', 'label' => trim($address->province.' '.$postal)];
            }
        }

        return null;
    }

    public static function choose(Request $request, string $province, ?string $postalCode): void
    {
        $request->session()->put(self::SESSION_KEY, ['province' => $province, 'postal_code' => self::cleanPostal($postalCode)]);
    }

    public static function forget(Request $request): void
    {
        $request->session()->forget(self::SESSION_KEY);
    }

    public static function region(?array $current): ?Region
    {
        return $current === null ? null : new Region('ZA', $current['province'], $current['postal_code']);
    }

    private static function cleanPostal(mixed $value): ?string
    {
        $value = trim((string) $value);

        return preg_match('/^\d{4}$/', $value) === 1 ? $value : null;
    }
}
