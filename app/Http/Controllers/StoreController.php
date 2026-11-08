<?php

namespace App\Http\Controllers;

use App\Enums\StoreStatus;
use App\Models\Store;
use App\Models\Team;
use App\Services\Addresses\AddressRules;
use App\Services\ProductImages;
use App\Services\Stores\StoreOnboarding;
use App\Services\Stores\StoreTrust;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * A seller's store: creating it, the set-up checklist and its status, the profile and storefront settings, the logo and
 * banner, the InfoDot company link and the submission for review. A user owns exactly one store (User::store()), so no URL here
 * carries a store id.
 */
class StoreController extends Controller
{
    /** The first step: who you are and where you ship from. Everything else is on the checklist that follows. */
    public function create(Request $request): View|RedirectResponse
    {
        if ($request->user()->store) {
            return redirect()->route('seller.dashboard');
        }

        return view('seller.store.create', ['provinces' => AddressRules::PROVINCES, 'email' => $request->user()->email]);
    }

    public function store(Request $request, StoreOnboarding $onboarding): RedirectResponse
    {
        if ($request->user()->store) {
            return redirect()->route('seller.dashboard');
        }

        $store = $onboarding->create($request->user(), $request->only(array_keys($onboarding->rules())));

        return redirect()->route('seller.store.status')
            ->with('status', $store->is_active
                ? 'Your store is live. Finish the checklist below, then list your first product.'
                : 'Your store is saved as a draft. Finish the checklist and send it for review.');
    }

    /** The status page: draft, waiting for review, live or suspended, with the operator's reason, and the checklist. */
    public function status(Request $request, StoreOnboarding $onboarding): View|RedirectResponse
    {
        $store = $request->user()->store;

        if (! $store) {
            return redirect()->route('sell.start');
        }

        return view('seller.store.status', [
            'store' => $store,
            'checklist' => $this->checklist($store, $onboarding),
            'missing' => $onboarding->missingForSubmission($store),
            'requiresReview' => (bool) config('emall.seller.requires_review'),
        ]);
    }

    public function submit(Request $request, StoreOnboarding $onboarding): RedirectResponse
    {
        $store = $request->user()->store;

        if (! $store) {
            return redirect()->route('sell.start');
        }

        try {
            $onboarding->submit($store);
        } catch (ValidationException $e) {
            return redirect()->route('seller.store.status')->withErrors($e->errors());
        }

        return redirect()->route('seller.store.status')->with('status', 'Sent for review. You will be told here and by notice as soon as the Emall team has decided.');
    }

    /**
     * Edit the signed-in user's one store. There is no store id in the URL on purpose: a user has exactly one store (see
     * User::store()), so there is nothing to guess and nothing to tamper with.
     */
    public function edit(Request $request): View|RedirectResponse
    {
        $store = $request->user()->store;

        if (! $store) {
            return redirect()->route('sell.start');
        }

        $store->load('team:id,name,infodot_team_uuid,infodot_verified_at');

        // Companies of the owner that InfoDot sent (a team with an InfoDot id): the only ones a store can be linked to.
        $companies = $request->user()->allTeams()->filter(fn ($t) => filled($t->infodot_team_uuid))->values();

        return view('seller.store.edit', [
            'store' => $store,
            'provinces' => AddressRules::PROVINCES,
            'companies' => $companies,
            'trust' => StoreTrust::for($store),
        ]);
    }

    /**
     * The slug is left alone when the name changes: it is part of the store's public identity and renaming should not break
     * shared links. Order history is unaffected either way (lines carry their own snapshot of the store name).
     */
    public function update(Request $request, StoreOnboarding $onboarding, ProductImages $images): RedirectResponse
    {
        $store = $request->user()->store;

        if (! $store) {
            return redirect()->route('sell.start');
        }

        $request->validate([
            'is_active' => 'nullable|boolean',
            'logo' => ['nullable', 'file', 'max:'.ProductImages::MAX_KILOBYTES, 'mimes:jpg,jpeg,png,webp'],
            'banner' => ['nullable', 'file', 'max:'.ProductImages::MAX_KILOBYTES, 'mimes:jpg,jpeg,png,webp'],
            'remove_logo' => 'nullable|boolean',
            'remove_banner' => 'nullable|boolean',
        ], ProductImages::messages(['logo', 'banner']));

        // A checkbox that is not ticked sends nothing: the form sends a 0 first, so an untick is heard.
        $data = $request->only(array_keys($onboarding->rules($store))) + ['name' => $store->name];
        $onboarding->update($store, $data);

        foreach (['logo' => 'logo_path', 'banner' => 'banner_path'] as $field => $column) {
            $old = $store->{$column};

            if ($request->hasFile($field)) {
                try {
                    $path = $images->storeMedia($request->file($field), $store->id, $field);
                } catch (ValidationException $e) {
                    throw ValidationException::withMessages([$field => collect($e->errors())->flatten()->first()]);
                }
                $store->update([$column => $path]);
                $old && Storage::disk('public')->delete($old);
            } elseif ($request->boolean('remove_'.$field) && $old) {
                $store->update([$column => null]);
                Storage::disk('public')->delete($old);
            }
        }

        // Only a store that is live (active) can be opened or closed by its owner; a draft, pending or suspended store stays
        // closed until the Emall team has decided.
        if ($store->status === StoreStatus::Active && $request->has('is_active')) {
            $store->update(['is_active' => $request->boolean('is_active')]);
        }

        return redirect()->route('seller.store.edit')->with('status', $store->is_active ? 'Store updated.' : 'Store updated and closed: your listings are hidden from the marketplace until you reopen it.');
    }

    /** Links the store to one of the owner's InfoDot companies. */
    public function linkTeam(Request $request, StoreOnboarding $onboarding): RedirectResponse
    {
        $store = $request->user()->store;

        if (! $store) {
            return redirect()->route('sell.start');
        }

        $data = $request->validate(['team_id' => 'required|integer|exists:teams,id']);

        try {
            $onboarding->linkTeam($store, Team::findOrFail($data['team_id']));
        } catch (AuthorizationException $e) {
            return back()->withErrors(['team_id' => $e->getMessage()]);
        }

        return redirect()->route('seller.store.edit')->with('status', 'Company linked.');
    }

    /**
     * The set-up checklist, each item with its REAL completion state read from the store, whether the review needs it, and where
     * to do it.
     *
     * @return array<int, array{key: string, label: string, done: bool, required: bool, hint: string, href: string, action: string}>
     */
    public function checklist(Store $store, StoreOnboarding $onboarding): array
    {
        $edit = route('seller.store.edit');
        $team = $store->team;
        $linked = $team !== null && filled($team->infodot_team_uuid);

        $items = [
            ['key' => 'identity', 'label' => 'Name and description', 'done' => filled($store->name) && filled($store->description), 'required' => false, 'hint' => 'Shoppers read this on your store page.', 'href' => $edit.'#identity', 'action' => 'Write it'],
            ['key' => 'logo', 'label' => 'Logo', 'done' => filled($store->logo_path), 'required' => false, 'hint' => 'Shown beside your name on every listing.', 'href' => $edit.'#identity', 'action' => 'Add a logo'],
            ['key' => 'banner', 'label' => 'Banner picture', 'done' => filled($store->banner_path), 'required' => false, 'hint' => 'The wide picture across the top of your store page.', 'href' => $edit.'#identity', 'action' => 'Add a banner'],
            ['key' => 'contact_email', 'label' => 'Contact e-mail', 'done' => filled($store->contact_email), 'required' => true, 'hint' => 'Where the Emall team reaches you about your store.', 'href' => $edit.'#contact', 'action' => 'Add an e-mail'],
            ['key' => 'region', 'label' => 'Province you ship from', 'done' => filled($store->region), 'required' => true, 'hint' => 'Shoppers see the province, never your street address.', 'href' => $edit.'#contact', 'action' => 'Choose a province'],
            ['key' => 'business', 'label' => 'Trading details', 'done' => filled($store->legal_name) || filled($store->registration_number) || $store->vat_registered, 'required' => false, 'hint' => 'Legal name, registration and VAT numbers. They are shown as claimed by you: Emall does not check them.', 'href' => $edit.'#business', 'action' => 'Add details'],
            ['key' => 'infodot_company', 'label' => 'InfoDot company linked', 'done' => $linked, 'required' => false, 'hint' => $linked ? 'Linked to '.$team->name.'.' : 'Link the company you trade as, from your InfoDot sign-in.', 'href' => $edit.'#company', 'action' => 'Link a company'],
            ['key' => 'shipping_policy', 'label' => 'Shipping policy', 'done' => filled($store->shipping_policy), 'required' => true, 'hint' => 'Tell shoppers how and when you send things.', 'href' => $edit.'#policies', 'action' => 'Write it'],
            ['key' => 'return_policy', 'label' => 'Returns policy', 'done' => filled($store->return_policy), 'required' => true, 'hint' => 'Shoppers can ask to return an item for '.(int) config('emall.returns.window_days', 7).' days after it arrives; say anything you add to that.', 'href' => $edit.'#policies', 'action' => 'Write it'],
            ['key' => 'shipping_methods', 'label' => 'A shipping method', 'done' => $store->shippingMethods()->where('is_active', true)->exists(), 'required' => true, 'hint' => 'A flat rate, free over an amount, or collection.', 'href' => route('seller.shipping'), 'action' => 'Add a method'],
            ['key' => 'first_product', 'label' => 'A first product', 'done' => $store->products()->exists(), 'required' => false, 'hint' => 'Products are checked by the Emall team before they appear.', 'href' => route('seller.products.create'), 'action' => 'List a product'],
        ];

        return $items;
    }
}
