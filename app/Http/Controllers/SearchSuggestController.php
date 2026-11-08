<?php

namespace App\Http\Controllers;

use App\Services\Search\Suggest;
use App\Support\Actor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/** Autocomplete for the search box: products, categories, brands, the person's own recent searches, popular ones. */
class SearchSuggestController extends Controller
{
    public function __invoke(Request $request, Suggest $suggest): JsonResponse
    {
        // Validated by hand: the app renders JSON errors only under /api, and this endpoint is called by script.
        $validator = Validator::make($request->query(), ['q' => ['nullable', 'string', 'max:60']]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $result = $suggest->for((string) ($validator->validated()['q'] ?? ''), Actor::forRequest($request));

        // Private to the person (their recent searches are in it) and never worth sharing between people.
        return response()->json($result)->header('Cache-Control', 'private, no-store');
    }
}
