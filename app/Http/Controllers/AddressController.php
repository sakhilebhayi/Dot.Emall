<?php

namespace App\Http\Controllers;

use App\Models\Address;
use App\Services\Addresses\AddressBook;
use App\Services\Addresses\AddressRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** The address book: list, add, edit, delete, make default. Every address is checked against AddressPolicy (only its owner). */
class AddressController extends Controller
{
    public function index(Request $request): View
    {
        return view('account.addresses', [
            'addresses' => Address::query()->where('user_id', $request->user()->id)->orderByDesc('is_default_shipping')->orderBy('id')->get(),
            'provinces' => AddressRules::PROVINCES,
            'editing' => null,
            'max' => AddressBook::MAX_ADDRESSES,
        ]);
    }

    public function edit(Request $request, Address $address): View
    {
        Gate::authorize('update', $address);

        return view('account.addresses', [
            'addresses' => Address::query()->where('user_id', $request->user()->id)->orderByDesc('is_default_shipping')->orderBy('id')->get(),
            'provinces' => AddressRules::PROVINCES, 'editing' => $address, 'max' => AddressBook::MAX_ADDRESSES,
        ]);
    }

    public function store(Request $request, AddressBook $book): RedirectResponse
    {
        try {
            $book->add($request->user(), $this->fields($request));
        } catch (ValidationException $e) {
            return redirect()->route('account.addresses')->withInput()->withErrors($e->errors());
        }

        return redirect()->route('account.addresses')->with('status', 'Address saved.');
    }

    public function update(Request $request, Address $address, AddressBook $book): RedirectResponse
    {
        Gate::authorize('update', $address);

        try {
            $book->update($address, $this->fields($request));
        } catch (ValidationException $e) {
            return redirect()->route('account.addresses.edit', $address)->withInput()->withErrors($e->errors());
        }

        return redirect()->route('account.addresses')->with('status', 'Address updated.');
    }

    public function destroy(Address $address, AddressBook $book): RedirectResponse
    {
        Gate::authorize('delete', $address);
        $book->delete($address);

        return redirect()->route('account.addresses')->with('status', 'Address removed. Orders you already placed keep the address they were sent to.');
    }

    public function makeDefault(Request $request, Address $address, AddressBook $book): RedirectResponse
    {
        Gate::authorize('update', $address);
        $book->setDefault($address, $request->input('kind') === 'billing' ? 'billing' : 'shipping');

        return redirect()->route('account.addresses')->with('status', 'Default address changed.');
    }

    /** @return array<string, mixed> only the fields an address has, with the two default boxes read as booleans */
    private function fields(Request $request): array
    {
        $in = $request->only(['label', 'recipient_name', 'phone', 'line1', 'line2', 'suburb', 'city', 'province', 'postal_code', 'country', 'instructions']);
        $in['country'] = $in['country'] ?? 'ZA';
        $in['is_default_shipping'] = $request->boolean('is_default_shipping');
        $in['is_default_billing'] = $request->boolean('is_default_billing');

        return $in;
    }
}
