<?php

namespace App\Services\Addresses;

use Illuminate\Validation\Rule;

/**
 * Validation for an address, South African where it can be: the nine provinces, a 4-digit postal code and an
 * optional phone. Other countries get generic rules (a postal code is any 3 to 10 letters, digits, spaces or hyphens
 * and a province is free text). Always allows a manual address; no lookup service is assumed.
 */
class AddressRules
{
    public const PROVINCES = [
        'Eastern Cape', 'Free State', 'Gauteng', 'KwaZulu-Natal', 'Limpopo', 'Mpumalanga', 'North West', 'Northern Cape', 'Western Cape',
    ];

    /**
     * @param  array<string, mixed>  $input  used only to read the country, so the right ruleset is returned
     * @return array<string, array<int, mixed>>
     */
    public static function for(array $input = [], string $prefix = ''): array
    {
        $country = strtoupper(trim((string) ($input['country'] ?? 'ZA'))) ?: 'ZA';
        $sa = $country === 'ZA';

        return [
            $prefix.'label' => ['nullable', 'string', 'max:40'],
            $prefix.'recipient_name' => ['required', 'string', 'max:120'],
            $prefix.'phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+()\-.\s]{5,40}$/'],
            $prefix.'line1' => ['required', 'string', 'max:160'],
            $prefix.'line2' => ['nullable', 'string', 'max:160'],
            $prefix.'suburb' => ['nullable', 'string', 'max:100'],
            $prefix.'city' => ['required', 'string', 'max:100'],
            $prefix.'province' => $sa ? ['required', 'string', Rule::in(self::PROVINCES)] : ['nullable', 'string', 'max:60'],
            $prefix.'postal_code' => $sa ? ['required', 'string', 'regex:/^\d{4}$/'] : ['required', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9\- ]{1,9}$/'],
            $prefix.'country' => ['required', 'string', 'size:2', 'alpha'],
            $prefix.'instructions' => ['nullable', 'string', 'max:255'],
            $prefix.'is_default_shipping' => ['nullable', 'boolean'],
            $prefix.'is_default_billing' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(string $prefix = ''): array
    {
        return [
            $prefix.'province.in' => 'Choose one of the nine provinces.',
            $prefix.'postal_code.regex' => 'A South African postal code is four digits.',
        ];
    }
}
