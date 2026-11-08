<?php

namespace App\Http\Controllers;

use App\Models\ReturnRequest;
use App\Services\Guests\GuestOrderAccess;
use App\Services\Returns\ReturnPhotos;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams one photo of a return request from the private disk. Only the buyer who asked (or the guest holding the order link in this
 * session), the seller it was asked of, and the Emall team can see it; everyone else gets a 404, as if it did not exist.
 */
class ReturnPhotoController extends Controller
{
    public function __invoke(Request $request, ReturnRequest $return, int $index, GuestOrderAccess $guests): StreamedResponse
    {
        $user = $request->user();
        $order = $return->order()->first();
        $allowed = ($user !== null && $user->can('view', $return))
            || ($user === null && $order !== null && $return->requester_id === null && $guests->hasSessionGrant($order));

        abort_unless($allowed, 404);

        $path = ($return->photo_paths ?? [])[$index] ?? null;
        abort_unless(is_string($path) && str_starts_with($path, 'returns/'.$return->order_id.'/') && ! str_contains($path, '..'), 404);

        $disk = Storage::disk(ReturnPhotos::DISK);
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, null, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
        ]);
    }
}
