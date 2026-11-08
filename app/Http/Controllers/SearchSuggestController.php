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

        $typed = (string) ($validator->validated()['q'] ?? '');
        $result = $suggest->for($typed, Actor::forRequest($request));
        $result['suggestions'] = $this->flatten($result, $typed);

        // Private to the person (their recent searches are in it) and never worth sharing between people.
        return response()->json($result)->header('Cache-Control', 'private, no-store');
    }

    /**
     * The one list the search box renders: products first, then categories, brands, the person's own recent searches and
     * popular ones, then a way to describe the need to the assistant. Every entry is a real link.
     *
     * @param  array<string, mixed>  $result
     * @return list<array{label: string, url: string, meta: string}>
     */
    private function flatten(array $result, string $typed): array
    {
        $out = [];

        foreach ($result['products'] as $p) {
            $out[] = ['label' => $p['title'], 'url' => route('products.show', $p['id']), 'meta' => 'R'.number_format((float) $p['price'], 2).($p['in_stock'] ? '' : ', out of stock')];
        }

        foreach ($result['categories'] as $c) {
            $out[] = ['label' => 'In '.$c['name'], 'url' => route('categories.show', $c['slug']), 'meta' => number_format((int) $c['count']).' '.($c['count'] == 1 ? 'product' : 'products')];
        }

        foreach ($result['brands'] as $b) {
            $out[] = ['label' => $b['label'] ?? $b['value'] ?? '', 'url' => route('search', ['brand' => [$b['value'] ?? $b['label'] ?? '']]), 'meta' => number_format((int) ($b['count'] ?? 0)).' products'];
        }

        foreach ($result['recent'] as $r) {
            $out[] = ['label' => (string) $r, 'url' => route('search', ['q' => $r]), 'meta' => 'Your recent search'];
        }

        foreach ($result['popular'] as $p) {
            $out[] = ['label' => $p['query'], 'url' => route('search', ['q' => $p['query']]), 'meta' => 'Popular search'];
        }

        if (mb_strlen(trim($typed)) >= 2) {
            $out[] = ['label' => 'Ask Emall: '.$typed, 'url' => route('assistant', ['text' => $typed]), 'meta' => 'Describe what you need'];
        }

        return array_slice($out, 0, 14);
    }
}
