<?php

namespace App\Http\Controllers;

use App\Services\Addresses\AddressRules;
use App\Services\Storefront\DeliveryRegion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Remembers where the visitor wants things delivered (province and optional postal code), for delivery prices on product pages. */
class DeliveryRegionController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        if ($request->input('use') === 'address') {
            DeliveryRegion::forget($request);

            return $this->back($request);
        }

        $data = $request->validate([
            'province' => ['required', 'string', Rule::in(AddressRules::PROVINCES)],
            'postal_code' => ['nullable', 'regex:/^\d{4}$/'],
        ], [
            'province.required' => 'Choose a province to see delivery.',
            'province.in' => 'Choose one of the nine provinces.',
            'postal_code.regex' => 'A postal code has four digits.',
        ]);

        DeliveryRegion::choose($request, $data['province'], $data['postal_code'] ?? null);

        return $this->back($request);
    }

    private function back(Request $request): RedirectResponse
    {
        $to = (string) $request->input('return', '');
        $path = str_starts_with($to, '/') && ! str_starts_with($to, '//') && ! str_contains($to, "\n") ? $to : '/products';

        return redirect($path);
    }
}
