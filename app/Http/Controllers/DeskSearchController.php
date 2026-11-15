<?php

namespace App\Http\Controllers;

use App\Services\DeskSearch\DeskSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The header search of the seller desk and the operator area (R-52): JSON groups of rows for the shared dot-ui search pill. The shop's
 * own search is public and is SearchSuggestController; this one is private to the signed-in role and never cached. A request names
 * a query and nothing else: whose store, whose parcels and whether the person is an operator are all read from the session.
 */
class DeskSearchController extends Controller
{
    public function seller(Request $request, DeskSearch $search): JsonResponse
    {
        abort_unless($request->user()?->store !== null, 403);

        return $this->respond($search->forSeller($request->user(), $this->query($request)));
    }

    public function operator(Request $request, DeskSearch $search): JsonResponse
    {
        abort_unless($request->user()?->is_platform_operator, 403);

        return $this->respond($search->forOperator($request->user(), $this->query($request)));
    }

    private function query(Request $request): string
    {
        $q = $request->query('q');

        return is_string($q) ? $q : '';
    }

    /** @param  list<array<string, mixed>>  $groups */
    private function respond(array $groups): JsonResponse
    {
        return response()->json(['groups' => $groups])->header('Cache-Control', 'private, no-store');
    }
}
