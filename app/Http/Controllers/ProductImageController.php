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
    public function store(Request $request, Product $product, ProductImages $images): RedirectResponse
    {
        Gate::authorize('update', $product);

        $request->validate(array_merge(ProductImages::rules(), ['images' => ['required', 'array', 'min:1', 'max:'.ProductImages::MAX_PER_PRODUCT]]));

        $added = $images->add($product, $request->file('images'));

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
