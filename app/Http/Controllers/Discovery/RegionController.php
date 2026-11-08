<?php

namespace App\Http\Controllers\Discovery;

use App\Http\Controllers\Controller;
use App\Support\ShopperRegion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Stores the shopper's province (or forgets it) and sends them back where they were. */
class RegionController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'province' => ['nullable', 'string', 'max:40'],
            'return' => ['nullable', 'string', 'max:2000'],
        ]);

        $province = ShopperRegion::remember($request, $request->input('province'));

        return redirect($this->safeReturn((string) $request->input('return')))
            ->with('status', $province ? 'Showing sellers and delivery for '.$province.'.' : 'Your province is cleared.');
    }

    /** Only a path on this site: anything else goes to the home page. */
    private function safeReturn(string $to): string
    {
        return str_starts_with($to, '/') && ! str_starts_with($to, '//') && ! str_contains($to, '\\') ? $to : url('/');
    }
}
