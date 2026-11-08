<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductImage;
use App\Services\ProductImages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * A seller's photos for one of their own listings: add (up to six), remove,
 * and pick the cover. Everything is authorised through ProductPolicy::update
 * and a photo can only be addressed through the listing it belongs to.
 */
class ProductImageController extends Controller
{
    public function store(Request $request, Product $product, ProductImages $images): RedirectResponse|\Illuminate\Http\JsonResponse
    {
        Gate::authorize('update', $product);

        // The drop zone sends the pictures itself (to show progress) and asks for JSON. The app renders JSON errors only under /api (D-41),
        // so a refusal is answered here as a 422 the page can print in place; a plain form post gets the usual redirect with errors.
        try {
            $request->validate(array_merge(ProductImages::rules(), ['images' => ['required', 'array', 'min:1', 'max:'.ProductImages::MAX_PER_PRODUCT]]));

            $added = $images->add($product, $request->file('images'));
        } catch (\Illuminate\Validation\ValidationException $e) {
            if ($request->wantsJson()) {
                return response()->json(['message' => collect($e->errors())->flatten()->first(), 'errors' => $e->errors()], 422);
            }

            throw $e;
        }

        if ($request->wantsJson()) {
            $request->session()->flash('status', $added->count().' '.($added->count() === 1 ? 'photo' : 'photos').' added.');

            return response()->json(['redirect' => route('seller.products.edit', $product).'#photos']);
        }

        return redirect()->route('seller.products.edit', $product)
            ->with('status', $added->count().' '.($added->count() === 1 ? 'photo' : 'photos').' added.');
    }

    public function destroy(Product $product, ProductImage $image, ProductImages $images): RedirectResponse
    {
        Gate::authorize('update', $product);

        $images->remove($image);

        return redirect()->route('seller.products.edit', $product)->with('status', 'Photo removed.');
    }

    public function cover(Product $product, ProductImage $image, ProductImages $images): RedirectResponse
    {
        Gate::authorize('update', $product);

        $images->makeCover($image);

        return redirect()->route('seller.products.edit', $product)->with('status', 'Cover photo changed.');
    }
}
