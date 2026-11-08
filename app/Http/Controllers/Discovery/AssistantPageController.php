<?php

namespace App\Http\Controllers\Discovery;

use App\Http\Controllers\Controller;
use App\Models\ProductImage;
use App\Services\Assistant\ShoppingAssistant;
use App\Services\Search\QueryUnderstanding;
use App\Support\Actor;
use App\Support\ShopperRegion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * "Ask Emall": the shopping assistant as a page. The request travels in the query string, so an answer is a link, the page works with no script, and
 * the understood parts (price, category, brand, stock, specifications) are links that take one away and ask again. The page calls the same
 * ShoppingAssistant service as the JSON endpoint (assistant.ask); it is rate limited the same way (8 a minute) but only when a question is asked.
 */
class AssistantPageController extends Controller
{
    private const KINDS = ['price', 'availability', 'brand', 'category', 'attribute'];

    public function __invoke(Request $request, ShoppingAssistant $assistant, QueryUnderstanding $understanding): View
    {
        $validated = $request->validate([
            'text' => ['nullable', 'string', 'max:'.config('discovery.assistant.max_text_length', 400)],
            'without' => ['nullable', 'array', 'max:5'],
            'without.*' => ['string', 'in:'.implode(',', self::KINDS)],
        ]);

        $text = trim((string) ($validated['text'] ?? ''));
        $without = array_values(array_unique($validated['without'] ?? []));
        $answer = null;
        $limited = false;

        if ($text !== '') {
            $key = 'assistant-page:'.(Actor::forRequest($request)->hash() ?? $request->ip());

            if (RateLimiter::tooManyAttempts($key, 8)) {
                $limited = true;
            } else {
                RateLimiter::hit($key, 60);
                $answer = $assistant->ask($text, ['region' => ShopperRegion::current($request), 'without' => $without])->toArray();
            }
        }

        $images = $answer ? ProductImage::query()->whereIn('id', array_filter(array_column($answer['catalogue']['items'], 'image_id')))->get()->keyBy('id') : collect();
        $detected = $text !== '' ? $understanding->parse($text)->detected : [];

        return view('discover.assistant', [
            'text' => $text,
            'without' => $without,
            'answer' => $answer,
            'limited' => $limited,
            'images' => $images,
            'chips' => $this->chips($detected, $text, $without),
            'searchParams' => $answer ? $this->searchParams($answer, $text) : [],
            'removeFor' => fn (string $searchKey) => $this->kindFor($searchKey),
            'askUrl' => fn (array $without) => route('assistant', array_filter(['text' => $text, 'without' => $without])),
        ]);
    }

    /**
     * What was understood, each as a toggle: still applied (a link that removes it) or removed (a link that puts it back).
     *
     * @param  list<array{kind: string, text: string}>  $detected
     * @param  list<string>  $without
     * @return list<array{kind: string, label: string, removed: bool, href: string}>
     */
    private function chips(array $detected, string $text, array $without): array
    {
        $byKind = [];

        foreach ($detected as $found) {
            if (in_array($found['kind'], self::KINDS, true)) {
                $byKind[$found['kind']][] = $found['text'];
            }
        }

        $chips = [];

        foreach ($byKind as $kind => $labels) {
            $removed = in_array($kind, $without, true);
            $next = $removed ? array_values(array_diff($without, [$kind])) : array_values(array_unique(array_merge($without, [$kind])));
            $chips[] = [
                'kind' => $kind,
                'label' => ucfirst(implode(', ', array_unique($labels))),
                'removed' => $removed,
                'href' => route('assistant', array_filter(['text' => $text, 'without' => $next])).'#answer',
            ];
        }

        return $chips;
    }

    /** The search page's equivalent of what the assistant searched, so the shopper can keep refining there. @return array<string, mixed> */
    private function searchParams(array $answer, string $text): array
    {
        $params = [];

        foreach ($answer['filters'] as $filter) {
            switch ($filter['key']) {
                case 'category':
                    $params['category'] = $filter['value'];
                    break;
                case 'brand':
                    $params['brand'][] = $filter['value'];
                    break;
                case 'price':
                    [$min, $max] = array_pad(explode('-', (string) $filter['value'], 2), 2, '');
                    $params['price_min'] = $min !== '' ? $min : null;
                    $params['price_max'] = $max !== '' ? $max : null;
                    break;
                case 'in_stock':
                    $params['in_stock'] = 1;
                    break;
                default:
                    if (str_starts_with($filter['key'], 'attr.')) {
                        $params['attr'][substr($filter['key'], 5)][] = $filter['value'];
                    }
            }
        }

        $params = array_filter($params, fn ($v) => $v !== null);

        return $params !== [] ? $params : ['q' => $text];
    }

    private function kindFor(string $searchKey): ?string
    {
        return match (true) {
            $searchKey === 'category' => 'category',
            $searchKey === 'price' => 'price',
            $searchKey === 'brand' => 'brand',
            $searchKey === 'in_stock' => 'availability',
            str_starts_with($searchKey, 'attr.') => 'attribute',
            default => null,
        };
    }
}
