<?php

namespace App\Services\Search;

use App\Support\Shop;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Turns a typed request into structure with deterministic rules: no model, no guessing beyond the catalogue.
 *
 *  - prices: "under R15,000", "below 5k", "between R2,000 and R5,000", "R1000-R2000", "over R500"
 *  - availability: "in stock", "available"
 *  - brand and category: matched against the real brand and category names in the catalogue (and, for categories,
 *    their synonyms: "tv" finds Televisions)
 *  - numbers with units: "55 inch", "256 gb", only when some listing really has that attribute value
 *  - "size 42", "red": only when a listing really has that value
 *  - synonyms: laptop / notebook, takkies / sneakers
 *
 * Whatever is not recognised stays as plain words for the text search.
 */
class QueryUnderstanding
{
    private const MONEY = '(?:r\s?|zar\s?)?((?>\d{1,3}(?:[ ,]\d{3})+(?:\.\d+)?|\d[\d,]*(?:\.\d+)?))\s?(k)?(?![a-z])';

    private const NOT_UNIT = '(?!\s?(?:inch|in\b|gb|tb|mb|kg|ml|litre|l\b|w\b|hz|cm|mm|mah|%|"))';

    public function __construct(
        private readonly Vocabulary $vocabulary,
        private readonly Synonyms $synonyms,
    ) {}

    /**
     * The request taken at its word: no prices, brands, categories or attributes are read out of it, only synonyms
     * are applied. Used when the person turns interpretation off and for the fallback after an inferred filter
     * found nothing.
     */
    public function literal(string $raw): ParsedQuery
    {
        $tokens = Text::tokens(mb_substr($raw, 0, (int) config('discovery.search.max_query_length', 100) * 2), true);
        $slots = [];
        $used = [];

        foreach ($tokens as $token) {
            $slots[] = $this->alternatives($token, $used);
        }

        return new ParsedQuery(trim($raw), implode(' ', $tokens), $tokens, $slots, $slots, [], [], $used);
    }

    /**
     * A request whose misspelt words are replaced, re-read for meaning.
     *
     * @param  array<string, string>  $replacements  wrong word => right word
     */
    public function parseCorrected(string $raw, array $replacements): ParsedQuery
    {
        $text = mb_strtolower($raw);

        foreach ($replacements as $wrong => $right) {
            $text = preg_replace('/\b'.preg_quote($wrong, '/').'s?\b/', $right, $text) ?? $text;
        }

        return $this->parse($text);
    }

    public function parse(string $raw): ParsedQuery
    {
        $raw = trim(mb_substr($raw, 0, (int) config('discovery.search.max_query_length', 100) * 2));
        $text = mb_strtolower($raw);
        $inferred = [];
        $detected = [];

        [$text, $price] = $this->extractPrice($text);

        foreach ($price as $key => $value) {
            $inferred[$key] = $value;
        }

        if (isset($price['price_max'], $price['price_min'])) {
            $detected[] = ['kind' => 'price', 'text' => $this->money($price['price_min']).' to '.$this->money($price['price_max'])];
        } elseif (isset($price['price_max'])) {
            $detected[] = ['kind' => 'price', 'text' => 'up to '.$this->money($price['price_max'])];
        } elseif (isset($price['price_min'])) {
            $detected[] = ['kind' => 'price', 'text' => 'from '.$this->money($price['price_min'])];
        }

        if (preg_match('/\b(in stock|available now|available|ready to ship)\b/', $text) === 1) {
            $text = preg_replace('/\b(in stock|available now|available|ready to ship)\b/', ' ', $text) ?? $text;
            $inferred['in_stock'] = true;
            $detected[] = ['kind' => 'availability', 'text' => 'in stock'];
        }

        $tokens = Text::tokens($text, true);
        $normalised = implode(' ', $tokens);

        $fullSlots = [];
        $slots = [];
        $synonymsUsed = [];
        $attributeHints = [];
        $longest = max(3, $this->synonyms->longest());
        $i = 0;
        $count = count($tokens);

        while ($i < $count) {
            $consumed = 0;

            for ($n = min($longest, $count - $i); $n >= 1 && $consumed === 0; $n--) {
                $phrase = implode(' ', array_slice($tokens, $i, $n));

                if (! isset($inferred['brand']) && ($brand = $this->vocabulary->brands()[$phrase] ?? null)) {
                    $inferred['brand'] = $brand['display'];
                    $detected[] = ['kind' => 'brand', 'text' => $brand['display']];
                    $fullSlots[] = [$phrase];
                    $consumed = $n;
                } elseif (! isset($inferred['category_id']) && ($category = $this->matchCategory($phrase))) {
                    $inferred['category_id'] = $category['id'];
                    $inferred['category_label'] = $category['display'];
                    $detected[] = ['kind' => 'category', 'text' => $category['display']];
                    $fullSlots[] = $this->alternatives($phrase, $synonymsUsed);
                    $consumed = $n;
                } elseif ($this->synonyms->has($phrase)) {
                    $alternatives = $this->alternatives($phrase, $synonymsUsed);
                    $slots[] = $alternatives;
                    $fullSlots[] = $alternatives;
                    $consumed = $n;
                }
            }

            if ($consumed === 0) {
                $token = $tokens[$i];
                $next = $tokens[$i + 1] ?? null;
                $unit = $next !== null ? (config('discovery.units')[$next] ?? null) : null;

                if ($unit !== null && preg_match('/^\d+(\.\d+)?$/', $token) === 1) {
                    $attributeHints[] = ['keys' => $unit, 'number' => (float) $token, 'text' => $token.' '.$next, 'slot' => [[$token], [$next]]];
                    $fullSlots[] = [$token];
                    $fullSlots[] = [$next];
                    $consumed = 2;
                } elseif ($token === 'size' && $next !== null) {
                    $attributeHints[] = ['keys' => ['size'], 'value' => $next, 'text' => 'size '.$next, 'slot' => [['size'], [$next]]];
                    $fullSlots[] = [$token];
                    $fullSlots[] = [$next];
                    $consumed = 2;
                } else {
                    $slots[] = [$token];
                    $fullSlots[] = [$token];
                    $consumed = 1;
                }
            }

            $i += $consumed;
        }

        $slots = $this->resolveAttributes($slots, $attributeHints, $inferred, $detected);

        return new ParsedQuery($raw, $normalised, $tokens, $slots, $fullSlots, $inferred, $detected, $synonymsUsed);
    }

    /**
     * Word attributes (a colour) are recognised only when a listing has that exact value.
     *
     * @param  list<list<string>>  $slots
     * @param  list<array<string, mixed>>  $hints
     * @param  array<string, mixed>  $inferred
     * @param  list<array{kind: string, text: string}>  $detected
     * @return list<list<string>>
     */
    private function resolveAttributes(array $slots, array $hints, array &$inferred, array &$detected): array
    {
        if (! Schema::hasTable('search_document_attributes')) {
            // No attribute data: every hint stays as the words it was typed as.
            foreach ($hints as $hint) {
                foreach ($hint['slot'] as $slot) {
                    $slots[] = $slot;
                }
            }

            return $slots;
        }

        foreach ($hints as $hint) {
            $row = null;

            if (isset($hint['number'])) {
                $row = DB::table('search_document_attributes')
                    ->whereIn('attr_key', $hint['keys'])
                    ->where('value_num', $hint['number'])
                    ->first(['attr_key', 'value_n']);
            } else {
                $row = DB::table('search_document_attributes')
                    ->whereIn('attr_key', $hint['keys'])
                    ->where('value_n', $hint['value'])
                    ->first(['attr_key', 'value_n']);
            }

            if ($row !== null && ! isset($inferred['attributes'][$row->attr_key])) {
                $inferred['attributes'][$row->attr_key] = [$row->value_n];
                $detected[] = ['kind' => 'attribute', 'text' => $hint['text']];
            } else {
                foreach ($hint['slot'] as $slot) {
                    $slots[] = $slot;
                }
            }
        }

        // A bare colour word.
        $words = array_values(array_unique(array_map(fn ($slot) => $slot[0], array_filter($slots, fn ($s) => count($s) === 1))));

        if ($words !== [] && count($words) <= 8) {
            $rows = DB::table('search_document_attributes')
                ->whereIn('attr_key', ['colour', 'color'])
                ->whereIn('value_n', $words)
                ->distinct()
                ->get(['attr_key', 'value_n']);

            foreach ($rows as $row) {
                if (! isset($inferred['attributes'][$row->attr_key])) {
                    $inferred['attributes'][$row->attr_key] = [$row->value_n];
                    $detected[] = ['kind' => 'attribute', 'text' => $row->value_n];
                    $slots = array_values(array_filter($slots, fn ($slot) => $slot !== [$row->value_n]));
                }
            }
        }

        return array_values($slots);
    }

    /** @return array{id: int, display: string}|null */
    private function matchCategory(string $phrase): ?array
    {
        $categories = $this->vocabulary->categories();

        if (isset($categories[$phrase])) {
            return $categories[$phrase];
        }

        foreach ($this->synonyms->equivalents($phrase) as $equivalent) {
            if (isset($categories[$equivalent])) {
                return $categories[$equivalent];
            }
        }

        return null;
    }

    /**
     * @param  list<array{from: string, to: string}>  $used
     * @return list<string>
     */
    private function alternatives(string $phrase, array &$used): array
    {
        $alternatives = [$phrase];

        foreach ($this->synonyms->equivalents($phrase) as $equivalent) {
            $alternatives[] = $equivalent;
            $used[] = ['from' => $phrase, 'to' => $equivalent];
        }

        return $alternatives;
    }

    /**
     * @return array{0: string, 1: array<string, float>}
     */
    private function extractPrice(string $text): array
    {
        $price = [];
        $m = self::MONEY;

        $between = '/\b(?:between\s+)?(?:r\s?|zar\s?)((?>\d{1,3}(?:[ ,]\d{3})+(?:\.\d+)?|\d[\d,]*(?:\.\d+)?))\s?(k)?(?![a-z])\s?(?:-|to|and)\s?(?:r\s?|zar\s?)?((?>\d{1,3}(?:[ ,]\d{3})+(?:\.\d+)?|\d[\d,]*(?:\.\d+)?))\s?(k)?(?![a-z])/';
        $between2 = '/\bbetween\s+((?>\d{1,3}(?:[ ,]\d{3})+(?:\.\d+)?|\d[\d,]*(?:\.\d+)?))\s?(k)?(?![a-z])\s+and\s+((?>\d{1,3}(?:[ ,]\d{3})+(?:\.\d+)?|\d[\d,]*(?:\.\d+)?))\s?(k)?(?![a-z])/';

        foreach ([$between, $between2] as $pattern) {
            if (preg_match($pattern, $text, $hit) === 1) {
                $low = $this->amount($hit[1], $hit[2] ?? '');
                $high = $this->amount($hit[3], $hit[4] ?? '');
                $price['price_min'] = min($low, $high);
                $price['price_max'] = max($low, $high);

                return [trim(str_replace($hit[0], ' ', $text)), $price];
            }
        }

        $upper = '/\b(?:under|below|less than|max(?:imum)?|up to|within|cheaper than|not more than|no more than|budget(?: of)?)\s+'.$m.self::NOT_UNIT.'/';

        if (preg_match($upper, $text, $hit) === 1 && $this->plausible($hit[0], $hit[1], $hit[2] ?? '')) {
            $price['price_max'] = $this->amount($hit[1], $hit[2] ?? '');
            $text = trim(str_replace($hit[0], ' ', $text));
        }

        $lower = '/\b(?:over|above|more than|at least|min(?:imum)?|from)\s+(?:r\s?|zar\s?)((?>\d{1,3}(?:[ ,]\d{3})+(?:\.\d+)?|\d[\d,]*(?:\.\d+)?))\s?(k)?(?![a-z])/';

        if (preg_match($lower, $text, $hit) === 1) {
            $price['price_min'] = $this->amount($hit[1], $hit[2] ?? '');
            $text = trim(str_replace($hit[0], ' ', $text));
        }

        return [$text, $price];
    }

    /** A bare number counts as a price only when it is clearly money: has an R, a k, or at least 100. */
    private function plausible(string $matched, string $digits, string $k): bool
    {
        if ($k !== '' || preg_match('/\b(r|zar)\s?\d/', $matched) === 1) {
            return true;
        }

        return $this->amount($digits, '') >= 100;
    }

    private function amount(string $digits, string $k): float
    {
        $value = (float) str_replace([',', ' '], '', $digits);

        return $k === 'k' ? $value * 1000 : $value;
    }

    private function money(float $amount): string
    {
        return Shop::amount(number_format($amount, 2, '.', ''));
    }
}
