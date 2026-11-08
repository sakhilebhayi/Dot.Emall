<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Cart\CartManager;
use App\Services\Cart\CartProblem;
use App\Services\Discovery\Track;
use App\Support\Actor;
use App\Support\Money;
use App\Support\Shop;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * "Add to cart" from the product page, for a guest (session cart) and a signed-in buyer alike. The browser sends a variant and a
 * quantity; price and stock are read from the database by CartManager. A product that comes in several choices and gets none
 * answers with the missing option names ("Choose Size and Colour first.") and sends the shopper back to the page.
 */
class ProductCartController extends Controller
{
    public function store(Request $request, Product $product, CartManager $cart, Track $track): RedirectResponse
    {
        $data = $request->validate([
            'variant_id' => ['nullable', 'integer'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:99'],
        ]);

        abort_unless(Product::query()->browsable()->whereKey($product->id)->exists(), 404);

        $variant = $this->variantFor($product, $data['variant_id'] ?? null);

        if ($variant === null) {
            return $this->back($product, $request)->withErrors(['variant_id' => 'Choose '.$this->optionNames($product).' first.'], 'cart');
        }

        try {
            $cart->add($request->user(), $variant, (int) ($data['quantity'] ?? 1));
        } catch (CartProblem $e) {
            return $this->back($product, $request, $variant)->withErrors([$e->field === 'product_id' ? 'quantity' : $e->field => $e->getMessage()], 'cart');
        }

        $track->addToCart(Actor::forRequest($request), $product);

        return $this->back($product, $request, $variant)->with('status', '"'.$product->name.'" is in your cart.')->with('cart_link', true);
    }

    /**
     * "Bought together": adds the product and the items offered with it in one go. Only products that need no choice are added;
     * the rest are named, never guessed.
     */
    public function bundle(Request $request, Product $product, CartManager $cart, Track $track): RedirectResponse
    {
        $data = $request->validate([
            'variant_id' => ['nullable', 'integer'],
            'items' => ['required', 'array', 'min:1', 'max:6'],
            'items.*' => ['integer'],
        ]);

        abort_unless(Product::query()->browsable()->whereKey($product->id)->exists(), 404);

        $anchor = $this->variantFor($product, $data['variant_id'] ?? null);

        if ($anchor === null) {
            return $this->back($product, $request)->withErrors(['variant_id' => 'Choose '.$this->optionNames($product).' first.'], 'cart');
        }

        $variants = [$anchor];
        $skipped = [];

        foreach (Product::query()->browsable()->whereIn('id', array_unique(array_map('intval', $data['items'])))->get() as $other) {
            $one = $this->variantFor($other, null);
            $one ? $variants[] = $one : $skipped[] = $other->name;
        }

        $added = 0;
        $failed = [];

        DB::transaction(function () use ($variants, $cart, $request, &$added, &$failed) {
            foreach ($variants as $variant) {
                try {
                    $cart->add($request->user(), $variant, 1);
                    $added++;
                } catch (CartProblem $e) {
                    $failed[] = $e->getMessage();
                }
            }
        });

        foreach ($variants as $variant) {
            $track->addToCart(Actor::forRequest($request), $variant->product);
        }

        $note = $added.' '.($added === 1 ? 'item is' : 'items are').' in your cart.';

        if ($skipped !== []) {
            $note .= ' '.implode(', ', $skipped).' comes in several options, so choose it on its own page.';
        }

        $redirect = $this->back($product, $request, $anchor)->with('status', $note)->with('cart_link', true);

        return $failed === [] ? $redirect : $redirect->withErrors(['quantity' => implode(' ', array_unique($failed))], 'cart');
    }

    /** The one variant a request means: the one named, or the only active one; null when a choice is still needed. */
    private function variantFor(Product $product, ?int $variantId): ?ProductVariant
    {
        $active = ProductVariant::query()->where('product_id', $product->id)->where('is_active', true)->orderByDesc('is_default')->orderBy('id')->get();

        if ($variantId !== null) {
            return $active->firstWhere('id', $variantId);
        }

        return $active->count() === 1 ? $active->first() : null;
    }

    private function optionNames(Product $product): string
    {
        $names = $product->options()->pluck('name')->all();

        return $names === [] ? 'an option' : implode(' and ', $names);
    }

    private function back(Product $product, Request $request, ?ProductVariant $variant = null): RedirectResponse
    {
        $query = $variant !== null && $product->options()->exists() ? ['variant' => $variant->id] : [];

        return redirect()->to(route('products.show', ['product' => $product] + $query).'#buy');
    }
}
