<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductModerationController extends Controller
{
    public function index(): View
    {
        return view('operator.products.index', [
            'pending' => Product::where('status', 'pending_approval')->with('store.owner')->latest()->get(),
            'reviewed' => Product::whereIn('status', ['approved', 'rejected'])->with('store.owner')->latest()->limit(20)->get(),
        ]);
    }

    public function approve(Product $product, Request $request): RedirectResponse
    {
        if ($product->status !== 'pending_approval') {
            return back()->withErrors(['status' => 'Only a pending listing can be approved.']);
        }

        $product->update([
            'status' => 'approved',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return back()->with('status', 'Listing approved.');
    }

    public function reject(Product $product, Request $request): RedirectResponse
    {
        if ($product->status !== 'pending_approval') {
            return back()->withErrors(['status' => 'Only a pending listing can be rejected.']);
        }

        $validated = $request->validate(['reason' => 'required|string']);

        $product->update([
            'status' => 'rejected',
            'rejected_reason' => $validated['reason'],
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return back()->with('status', 'Listing rejected.');
    }
}
