<?php

namespace App\Services\Assistant;

use App\Contracts\CatalogueSource;
use App\Services\Search\ParsedQuery;
use App\Services\Search\QueryUnderstanding;
use App\Services\Search\SearchEngine;
use App\Services\Search\SearchHit;
use App\Services\Search\SearchRequest;
use App\Services\Search\SearchResult;
use App\Services\Search\Text;
use App\Support\Shop;

/**
 * A shopping helper that turns "a reliable laptop for university under R15,000" into a real search and a short list.
 *
 * The understanding is deterministic (QueryUnderstanding plus a small table of everyday words), the products come from
 * the real catalogue search, and every line of explanation is built from facts read off those products: price,
 * what is in stock, the review average and count, the delivery estimate when one is recorded, and their
 * structured attributes. Nothing is invented; a fact that is not recorded is simply not mentioned.
 *
 * With ANTHROPIC_API_KEY set, a model may REWRITE the explanation and propose ways to narrow the search, but its
 * output is checked against the facts and discarded line by line when it strays (see validate*). The answer says
 * which wrote it (mode "rules" or "model").
 */
class ShoppingAssistant
{
    /** Everyday words that describe what the shopper wants without being a product word: they steer the sort, they are never required in a title. */
    private const SORT_WORDS = [
        'reliable' => 'rating', 'dependable' => 'rating', 'durable' => 'rating', 'trusted' => 'rating', 'best' => 'rating', 'popular' => 'best_selling', 'bestselling' => 'best_selling',
        'cheap' => 'price_asc', 'cheapest' => 'price_asc', 'affordable' => 'price_asc', 'inexpensive' => 'price_asc',
        'newest' => 'newest', 'latest' => 'newest',
    ];

    /** Words that name a use, not a product. They are mentioned back to the shopper and never searched for. */
    private const PURPOSE_WORDS = ['university', 'uni', 'college', 'student', 'everyday', 'daily'];

    /** Filler of a conversational request. Dropped without comment. */
    private const FILLER = ['good', 'great', 'nice', 'decent', 'quality', 'recommend', 'suggest', 'something', 'anything', 'looking', 'buy', 'get', 'would', 'like', 'can', 'you', 'could', 'give', 'show', 'help', 'find', 'please'];

    /** Phrases a model may not use because the catalogue facts cannot support them. */
    private const UNSUPPORTED = ['warranty', 'guarantee', 'certified', 'genuine', 'authentic', 'waterproof', 'discount', 'on sale', 'free delivery', 'free shipping', 'cheapest', 'fastest', 'lowest price', 'best price', 'award', 'recommended by', 'limited time'];

    public function __construct(
        private readonly QueryUnderstanding $understanding,
        private readonly SearchEngine $engine,
        private readonly CatalogueSource $catalogue,
        private readonly AssistantModel $model,
    ) {}

    /**
     * @param  array{region?: string|null, without?: list<string>}  $context  `without` lists understood kinds (price, availability, brand, category, attribute) the shopper removed
     */
    public function ask(string $text, array $context = []): AssistantAnswer
    {
        $max = (int) config('discovery.assistant.max_text_length', 400);
        $text = trim(mb_substr(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? '', 0, $max));
        $notes = [];

        if ($text === '') {
            return $this->nothing($text, ['Tell me what you are looking for, for example "a reliable laptop for university under R15,000".']);
        }

        [$cleaned, $sort, $purposes] = $this->steer($text);
        $parsed = $this->understanding->parse($cleaned);

        if ($purposes !== []) {
            $notes[] = 'I read "'.implode('", "', $purposes).'" as a wish for '.$this->sortLabel($sort).', not as a product word. The catalogue has no data about how well a product suits a purpose, so I have not claimed any.';
        }

        $without = array_values(array_intersect((array) ($context['without'] ?? []), ['price', 'availability', 'brand', 'category', 'attribute']));
        $request = $without === []
            ? SearchRequest::fromArray(['q' => $cleaned, 'sort' => $sort])
            : $this->explicit($parsed, $sort, $without);
        $request = $request->with(['inStock' => false, 'perPage' => (int) config('discovery.assistant.max_items', 5), 'page' => 1]);

        if ($without !== []) {
            $notes[] = 'You removed what I understood about '.implode(', ', array_map(fn ($k) => match ($k) {'availability' => 'stock', 'attribute' => 'specifications', default => $k}, $without)).'. The search ignores it.';
        }
        $result = $this->engine->search($request->with(['inStock' => ! in_array('availability', $without, true)]));

        if ($result->isEmpty()) {
            $result = $this->engine->search($request);

            if (! $result->isEmpty()) {
                $notes[] = 'Nothing matching is in stock right now, so these are out of stock.';
            }
        }

        if ($result->isEmpty()) {
            return new AssistantAnswer('rules', $text, $result->understood, $result->appliedFilters, 0, [], [
                'headline' => 'Nothing in the catalogue matches that.',
                'picks' => [],
                'refinements' => $this->relaxations($result),
            ], array_merge($notes, ['I only suggest products that exist in the catalogue, so I have nothing to offer for this request.']));
        }

        $facts = $this->factsFor($result->items, $parsed);
        $picks = [];

        foreach ($result->items as $hit) {
            $picks[] = ['product_id' => $hit->product->id, 'reason' => $this->reasonFor($hit, $facts[$hit->product->id], $parsed), 'source' => 'rules'];
        }

        $suggestion = [
            'headline' => $this->headline($result, $parsed),
            'picks' => $picks,
            'refinements' => $this->refinements($result),
        ];

        $mode = 'rules';

        if ($this->model->enabled()) {
            [$suggestion, $modelWrote] = $this->withModel($text, $result, $facts, $suggestion, $parsed);

            if ($modelWrote) {
                $mode = 'model';
            } else {
                $notes[] = 'The writing model gave nothing usable, so the explanation above is the rule-based one.';
            }
        }

        $catalogue = [];

        foreach ($result->items as $hit) {
            $catalogue[] = [
                'product_id' => $hit->product->id,
                'name' => $hit->product->name,
                'store' => $hit->product->store?->name ?? '',
                'image_id' => $hit->product->images->first()?->id,
                'facts' => $facts[$hit->product->id]['list'],
            ];
        }

        return new AssistantAnswer($mode, $text, $result->understood, $result->appliedFilters, $result->total, $catalogue, $suggestion, $notes);
    }

    /**
     * The request with some of what was understood taken away. The engine applies what it infers unless the shopper set it, so the kept parts become explicit
     * filters, the search turns literal, and the words behind a removed brand or category go back into the text.
     *
     * @param  list<string>  $without
     */
    private function explicit(ParsedQuery $parsed, string $sort, array $without): SearchRequest
    {
        $inferred = $parsed->inferred;
        $words = [$parsed->residualText()];

        foreach ($parsed->detected as $found) {
            if (in_array($found['kind'], ['brand', 'category'], true) && in_array($found['kind'], $without, true)) {
                $words[] = $found['text'];
            }
        }

        $request = new SearchRequest(text: trim(implode(' ', array_filter($words))), sort: $sort, understand: false);

        if (isset($inferred['category_id']) && ! in_array('category', $without, true)) {
            $request->categoryId = (int) $inferred['category_id'];
        }

        if (isset($inferred['brand']) && ! in_array('brand', $without, true)) {
            $request->brands = [$inferred['brand']];
        }

        if (! in_array('price', $without, true)) {
            $request->priceMin = isset($inferred['price_min']) ? (float) $inferred['price_min'] : null;
            $request->priceMax = isset($inferred['price_max']) ? (float) $inferred['price_max'] : null;
        }

        if (! empty($inferred['in_stock']) && ! in_array('availability', $without, true)) {
            $request->inStock = true;
        }

        if (! in_array('attribute', $without, true)) {
            $request->attributes = array_map(fn ($v) => array_map('strval', (array) $v), $inferred['attributes'] ?? []);
        }

        return $request;
    }

    // --- understanding ------------------------------------------------------------------------------------------

    /**
     * Separates product words from words about what the shopper wants.
     *
     * @return array{0: string, 1: string, 2: list<string>} the text to search, the sort, the steering words found
     */
    private function steer(string $text): array
    {
        $sort = 'relevance';
        $found = [];
        $kept = [];

        foreach (preg_split('/\s+/', mb_strtolower($text)) ?: [] as $word) {
            $bare = trim($word, " \t,.;:!?\"'()");

            if (isset(self::SORT_WORDS[$bare])) {
                $sort = $sort === 'relevance' ? self::SORT_WORDS[$bare] : $sort;
                $found[] = $bare;

                continue;
            }

            if (in_array($bare, self::PURPOSE_WORDS, true)) {
                $found[] = $bare;

                continue;
            }

            if (in_array($bare, self::FILLER, true)) {
                continue;
            }

            $kept[] = $word;
        }

        return [trim(implode(' ', $kept)), $sort, array_values(array_unique($found))];
    }

    private function sortLabel(string $sort): string
    {
        return match ($sort) {
            'rating' => 'products with the best real review averages first',
            'price_asc' => 'the lowest price first',
            'newest' => 'the newest listings first',
            'best_selling' => 'the products most bought first',
            default => 'the closest match',
        };
    }

    // --- facts --------------------------------------------------------------------------------------------------

    /**
     * @param  list<SearchHit>  $hits
     * @return array<int, array{list: list<array{label: string, value: string}>, map: array<string, string>, numbers: list<string>}>
     */
    private function factsFor(array $hits, ParsedQuery $parsed): array
    {
        $ids = array_map(fn (SearchHit $h) => $h->product->id, $hits);
        $attributes = $this->catalogue->attributes($ids);
        $out = [];

        foreach ($hits as $hit) {
            $product = $hit->product;
            $list = [];

            $list[] = ['label' => 'Price', 'value' => Shop::amount($product->price)];
            $list[] = ['label' => 'Stock', 'value' => $product->stock > 0 ? $product->stock.' in stock' : 'Out of stock'];

            if ($product->rating_count > 0) {
                $list[] = ['label' => 'Rating', 'value' => number_format((float) $product->rating_average, 1).' stars from '.$product->rating_count.' '.($product->rating_count === 1 ? 'review' : 'reviews')];
            } else {
                $list[] = ['label' => 'Rating', 'value' => 'No reviews yet'];
            }

            if ($hit->brand) {
                $list[] = ['label' => 'Brand', 'value' => $hit->brand];
            }

            $delivery = $this->catalogue->deliveryEstimate($product->id);

            if ($delivery !== null) {
                $list[] = ['label' => 'Delivery estimate', 'value' => ($delivery['min'] === $delivery['max'] ? $delivery['min'] : $delivery['min'].' to '.$delivery['max']).' working days (the seller\'s estimate)'];
            }

            foreach (array_slice($attributes[$product->id] ?? [], 0, 4) as $attribute) {
                $list[] = ['label' => $attribute['label'], 'value' => trim($attribute['value'].' '.($attribute['unit'] ?? ''))];
            }

            $list[] = ['label' => 'Sold by', 'value' => $product->store?->name ?? ''];

            $map = [];
            $numbers = [];

            foreach ($list as $fact) {
                $map[$fact['label']] = $fact['value'];

                foreach ($this->numbersIn($fact['value']) as $n) {
                    $numbers[] = $n;
                }
            }

            $out[$product->id] = ['list' => $list, 'map' => $map, 'numbers' => array_values(array_unique($numbers))];
        }

        return $out;
    }

    /** @return list<string> every number in the text, written without separators ("R12,499.00" gives 12499.00 and 12499) */
    private function numbersIn(string $text): array
    {
        $text = preg_replace('/(?<=\d)[,\s](?=\d{3}\b)/', '', $text) ?? $text;
        preg_match_all('/\d+(?:\.\d+)?/', $text, $m);
        $out = [];

        foreach ($m[0] as $number) {
            $out[] = $number;

            if (str_contains($number, '.')) {
                $out[] = rtrim(rtrim($number, '0'), '.');
                $out[] = explode('.', $number)[0];
            }
        }

        return array_values(array_unique($out));
    }

    // --- rule-based explanation ---------------------------------------------------------------------------------

    /**
     * @param  array{list: list<array{label: string, value: string}>, map: array<string, string>, numbers: list<string>}  $facts
     */
    private function reasonFor(SearchHit $hit, array $facts, ParsedQuery $parsed): string
    {
        $product = $hit->product;
        $parts = [];
        $budget = $parsed->inferred['price_max'] ?? null;

        if ($budget !== null) {
            $under = (float) $budget - (float) $product->price;
            $parts[] = $under >= 0
                ? $facts['map']['Price'].', '.Shop::amount(number_format($under, 2, '.', '')).' under your '.Shop::amount(number_format((float) $budget, 2, '.', '')).' budget'
                : $facts['map']['Price'];
        } else {
            $parts[] = $facts['map']['Price'];
        }

        $parts[] = $product->rating_count > 0 ? 'rated '.$facts['map']['Rating'] : 'no reviews yet';
        $parts[] = strtolower($facts['map']['Stock']);

        if (isset($facts['map']['Delivery estimate'])) {
            $parts[] = 'estimated delivery '.$facts['map']['Delivery estimate'];
        }

        foreach (array_slice($facts['list'], 0, 12) as $fact) {
            if (! in_array($fact['label'], ['Price', 'Stock', 'Rating', 'Brand', 'Delivery estimate', 'Sold by'], true)) {
                $parts[] = strtolower($fact['label']).' '.$fact['value'];
            }
        }

        $why = $hit->why();

        return ucfirst(implode('; ', $parts)).'.'.($why !== '' ? ' '.$why.'.' : '');
    }

    private function headline(SearchResult $result, ParsedQuery $parsed): string
    {
        $count = count($result->items);
        $of = $result->total > $count ? " (the first {$count} of {$result->total})" : '';

        return $result->total === 1
            ? 'I found 1 product in the catalogue that fits.'
            : "I found {$result->total} products in the catalogue that fit{$of}.";
    }

    /**
     * @return list<array{label: string, params: array<string, mixed>}>
     */
    private function refinements(SearchResult $result): array
    {
        $out = [];

        foreach (array_slice($result->facets['brands'] ?? [], 0, 3) as $brand) {
            if (! $brand['selected'] && $brand['count'] < $result->total) {
                $out[] = ['label' => 'Only '.$brand['label'].' ('.$brand['count'].')', 'params' => ['brand' => [$brand['value']]]];
            }
        }

        $stock = $result->facets['availability'] ?? null;

        if ($stock && $stock['out_of_stock'] > 0 && ! $stock['selected']) {
            $out[] = ['label' => 'Only what is in stock ('.$stock['in_stock'].')', 'params' => ['in_stock' => true]];
        }

        $rating = $result->facets['rating'][0] ?? null;

        if ($rating && $rating['count'] > 0 && $rating['count'] < $result->total && ! $rating['selected']) {
            $out[] = ['label' => 'Rated 4 stars and up ('.$rating['count'].')', 'params' => ['rating' => 4]];
        }

        return array_slice($out, 0, 4);
    }

    /** @return list<array{label: string, params: array<string, mixed>}> */
    private function relaxations(SearchResult $result): array
    {
        return array_map(fn ($r) => ['label' => 'Remove "'.$r['label'].'" to see '.$r['count'], 'params' => ['remove' => $r['remove']]], array_slice($result->relaxations, 0, 3));
    }

    // --- optional model -----------------------------------------------------------------------------------------

    /**
     * @param  array<int, array{list: array, map: array<string, string>, numbers: list<string>}>  $facts
     * @param  array{headline: string, picks: list<array{product_id: int, reason: string, source: string}>, refinements: list<array>}  $suggestion
     * @return array{0: array, 1: bool}
     */
    private function withModel(string $text, SearchResult $result, array $facts, array $suggestion, ParsedQuery $parsed): array
    {
        $payload = [];

        foreach ($result->items as $hit) {
            $payload[] = ['product_id' => $hit->product->id, 'name' => $hit->product->name, 'facts' => $facts[$hit->product->id]['map']];
        }

        $written = $this->model->write($text, $payload);

        if ($written === null) {
            return [$suggestion, false];
        }

        $used = false;
        $budget = array_values(array_filter([$parsed->inferred['price_max'] ?? null, $parsed->inferred['price_min'] ?? null], fn ($v) => $v !== null));
        $budgetNumbers = [];

        foreach ($budget as $value) {
            array_push($budgetNumbers, ...$this->numbersIn((string) $value));
        }

        $allowedHeadline = [];

        foreach ($facts as $f) {
            array_push($allowedHeadline, ...$f['numbers']);
        }

        array_push($allowedHeadline, (string) $result->total, (string) count($result->items));

        if (isset($written['headline']) && $this->safe((string) $written['headline'], 140, array_merge($allowedHeadline, $budgetNumbers), array_map(fn ($h) => $h->product->name, $result->items))) {
            $suggestion['headline'] = trim((string) $written['headline']);
            $used = true;
        }

        $byId = [];

        foreach ($suggestion['picks'] as $i => $pick) {
            $byId[$pick['product_id']] = $i;
        }

        foreach ((array) ($written['picks'] ?? []) as $pick) {
            $id = is_array($pick) ? ($pick['product_id'] ?? null) : null;

            if (! is_int($id) || ! isset($byId[$id]) || ! is_string($pick['reason'] ?? null)) {
                continue;
            }

            $productFacts = $facts[$id];
            $names = array_map(fn ($h) => $h->product->name, array_filter($result->items, fn ($h) => $h->product->id !== $id));

            if ($this->safe($pick['reason'], 240, array_merge($productFacts['numbers'], $budgetNumbers), $names)) {
                $suggestion['picks'][$byId[$id]] = ['product_id' => $id, 'reason' => trim($pick['reason']), 'source' => 'model'];
                $used = true;
            }
        }

        $refinements = [];
        $brands = array_column($result->facets['brands'] ?? [], 'count', 'value');

        foreach ((array) ($written['refinements'] ?? []) as $r) {
            if (! is_array($r)) {
                continue;
            }

            if (($r['type'] ?? null) === 'brand' && is_string($r['value'] ?? null) && isset($brands[$r['value']])) {
                $refinements[] = ['label' => 'Only '.$r['value'].' ('.$brands[$r['value']].')', 'params' => ['brand' => [$r['value']]]];
            } elseif (($r['type'] ?? null) === 'in_stock') {
                $refinements[] = ['label' => 'Only what is in stock ('.($result->facets['availability']['in_stock'] ?? 0).')', 'params' => ['in_stock' => true]];
            }
        }

        if ($refinements !== []) {
            $suggestion['refinements'] = array_slice($refinements, 0, 4);
            $used = true;
        }

        return [$suggestion, $used];
    }

    /**
     * A model line passes only if it is short, plain, links nothing, names no other product, and every number in it
     * is one that appears in the facts (or the shopper's own budget).
     *
     * @param  list<string>  $allowedNumbers
     * @param  list<string>  $foreignNames  names of OTHER products that must not appear
     */
    private function safe(string $line, int $max, array $allowedNumbers, array $foreignNames): bool
    {
        $line = trim($line);

        if ($line === '' || mb_strlen($line) > $max || preg_match('~https?:|www\.|@|[<>{}\[\]`]|\*\*~i', $line) === 1) {
            return false;
        }

        foreach ($this->numbersIn($line) as $number) {
            if (! in_array($number, $allowedNumbers, true)) {
                return false;
            }
        }

        $normalised = Text::normalise($line);

        foreach (self::UNSUPPORTED as $phrase) {
            if (str_contains($normalised, $phrase)) {
                return false;
            }
        }

        foreach ($foreignNames as $name) {
            if (mb_strlen($name) >= 6 && str_contains($normalised, Text::normalise($name))) {
                return false;
            }
        }

        return true;
    }

    /** @param  list<string>  $notes */
    private function nothing(string $text, array $notes): AssistantAnswer
    {
        return new AssistantAnswer('rules', $text, [], [], 0, [], ['headline' => 'What would you like to find?', 'picks' => [], 'refinements' => []], $notes);
    }
}
