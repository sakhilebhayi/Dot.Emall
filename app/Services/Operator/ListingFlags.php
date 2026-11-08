<?php

namespace App\Services\Operator;

use App\Models\ListingFlagDismissal;
use App\Models\Product;
use App\Support\Money;
use App\Support\Shop;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Listings the simple rules below would like a person to look at. A REVIEW AID: every flag carries the rule that raised it and the
 * evidence, nothing here unlists, bans or hides anything, and a listing an operator has judged fine stays dismissed for that rule.
 *
 *   price_anomaly   the price is far below or far above the median of its category (config emall.flags: ratios, minimum peers)
 *   prohibited_term the name or description contains a word from config emall.prohibited_terms (whole words)
 *   new_store_value a store younger than N days with many listings priced above a threshold
 *
 * A flag is a question ("is this a counterfeit?"), not an answer. The page says so.
 */
class ListingFlags
{
    public const RULES = [
        'price_anomaly' => 'Price far from the category median',
        'prohibited_term' => 'Contains a word from the prohibited-items list',
        'new_store_value' => 'New store with many high-value listings',
    ];

    /** @return Collection<int, array{product: Product, rule: string, rule_label: string, detail: string}> */
    public function all(?string $rule = null): Collection
    {
        $candidates = $this->candidates();

        if ($candidates->isEmpty()) {
            return collect();
        }

        $flags = collect();

        if ($rule === null || $rule === 'price_anomaly') {
            $flags = $flags->concat($this->priceAnomalies($candidates));
        }

        if ($rule === null || $rule === 'prohibited_term') {
            $flags = $flags->concat($this->prohibited($candidates));
        }

        if ($rule === null || $rule === 'new_store_value') {
            $flags = $flags->concat($this->newStoreValue($candidates));
        }

        $dismissed = ListingFlagDismissal::query()->whereIn('product_id', $candidates->pluck('id'))->get(['product_id', 'rule'])
            ->map(fn ($d) => $d->product_id.':'.$d->rule)->flip();

        return $flags->reject(fn (array $f) => $dismissed->has($f['product']->id.':'.$f['rule']))->values();
    }

    public function count(): int
    {
        return $this->all()->count();
    }

    /** @return array<string, string> rule => the rule in words, with the numbers in force */
    public function describe(): array
    {
        $c = config('emall.flags');

        return [
            'price_anomaly' => sprintf('Priced under %s%% or over %s times the median of its category, when the category has at least %d other listings.', rtrim(rtrim(number_format($c['low_ratio'] * 100, 1), '0'), '.'), rtrim(rtrim(number_format($c['high_ratio'], 1), '0'), '.'), $c['min_peers']),
            'prohibited_term' => 'Name or description contains a whole word from the prohibited-items list ('.count((array) config('emall.prohibited_terms')).' words, set in config).',
            'new_store_value' => sprintf('Store opened in the last %d days with at least %d listings priced at %s or more.', $c['new_store_days'], $c['high_value_count'], Shop::money($c['high_value_cents'])),
        ];
    }

    /** Pending listings, plus those listed or edited recently: a bounded scan, never the whole catalogue. */
    private function candidates(): Collection
    {
        $since = now()->subDays((int) config('emall.flags.scan_days', 60));

        return Product::query()
            ->whereIn('status', ['pending_approval', 'approved'])
            ->where(fn ($q) => $q->where('status', 'pending_approval')->orWhere('updated_at', '>=', $since))
            ->with('store:id,name,owner_id,created_at')
            ->orderByDesc('id')->limit(2000)->get();
    }

    private function priceAnomalies(Collection $candidates): Collection
    {
        $c = config('emall.flags');
        $categories = $candidates->pluck('category_id')->filter()->unique()->values();

        if ($categories->isEmpty()) {
            return collect();
        }

        $prices = DB::table('products')->whereIn('category_id', $categories)->where('status', 'approved')->whereNull('deleted_at')
            ->get(['id', 'category_id', 'price'])->groupBy('category_id');

        $out = collect();

        foreach ($candidates as $product) {
            if ($product->category_id === null) {
                continue;
            }

            $peers = ($prices[$product->category_id] ?? collect())->where('id', '!=', $product->id)->pluck('price')->map(fn ($p) => (float) $p)->sort()->values();

            if ($peers->count() < $c['min_peers']) {
                continue;
            }

            $median = $this->median($peers->all());
            $price = (float) $product->price;

            if ($median > 0 && $price < $median * $c['low_ratio']) {
                $out->push($this->flag($product, 'price_anomaly', sprintf('%s is %s of the category median of %s (%d other listings). Unusually cheap goods are a counterfeit signal; it may also be a pricing slip.', Shop::amount($price), $this->percent($price / $median), Shop::amount($median), $peers->count())));
            } elseif ($median > 0 && $price > $median * $c['high_ratio']) {
                $out->push($this->flag($product, 'price_anomaly', sprintf('%s is %s times the category median of %s (%d other listings). It may be a typing slip, or a different class of product.', Shop::amount($price), rtrim(rtrim(number_format($price / $median, 1), '0'), '.'), Shop::amount($median), $peers->count())));
            }
        }

        return $out;
    }

    private function prohibited(Collection $candidates): Collection
    {
        $terms = array_values(array_filter(array_map(fn ($t) => mb_strtolower(trim((string) $t)), (array) config('emall.prohibited_terms'))));

        if ($terms === []) {
            return collect();
        }

        $pattern = '/(?<![\p{L}\p{N}])('.implode('|', array_map(fn ($t) => preg_quote($t, '/'), $terms)).')(?![\p{L}\p{N}])/iu';
        $out = collect();

        foreach ($candidates as $product) {
            if (preg_match_all($pattern, $product->name."\n".$product->description, $m) > 0) {
                $found = collect($m[1])->map(fn ($w) => mb_strtolower($w))->unique()->take(5)->implode('", "');
                $out->push($this->flag($product, 'prohibited_term', "The listing text contains \"{$found}\". The launch rules allow no alcohol, tobacco, firearms, medicines or adult goods, and \"replica\" is how counterfeit sellers describe goods. Check what is actually being sold."));
            }
        }

        return $out;
    }

    private function newStoreValue(Collection $candidates): Collection
    {
        $c = config('emall.flags');
        $young = $candidates->filter(fn (Product $p) => $p->store && $p->store->created_at && $p->store->created_at->gte(now()->subDays($c['new_store_days'])));

        if ($young->isEmpty()) {
            return collect();
        }

        $counts = DB::table('products')->whereIn('store_id', $young->pluck('store_id')->unique())->whereNull('deleted_at')
            ->where('price', '>=', Money::fromCents($c['high_value_cents']))->whereIn('status', ['pending_approval', 'approved'])
            ->groupBy('store_id')->selectRaw('store_id, count(*) as n')->pluck('n', 'store_id');

        // One flag per store, not one per listing: the representative is the store's lowest-numbered high-value listing, so a dismissal sticks.
        return $young->filter(fn (Product $p) => (float) $p->price >= $c['high_value_cents'] / 100 && ($counts[$p->store_id] ?? 0) >= $c['high_value_count'])
            ->groupBy('store_id')
            ->map(function (Collection $products) use ($c, $counts) {
                $p = $products->sortBy('id')->first();

                return $this->flag($p, 'new_store_value', sprintf('%s opened %s ago and has %d listings priced at %s or more (%s is the first of them). New stores with expensive stock are where identity abuse and counterfeits start; most are honest. Check the store\'s details first, then its listings.', $p->store->name, $p->store->created_at->diffForHumans(null, true), $counts[$p->store_id], Shop::money($c['high_value_cents']), $p->name));
            })
            ->values();
    }

    private function flag(Product $product, string $rule, string $detail): array
    {
        return ['product' => $product, 'rule' => $rule, 'rule_label' => self::RULES[$rule], 'detail' => $detail];
    }

    /** @param  list<float>  $sorted */
    private function median(array $sorted): float
    {
        $n = count($sorted);
        $mid = intdiv($n, 2);

        return $n % 2 ? $sorted[$mid] : ($sorted[$mid - 1] + $sorted[$mid]) / 2;
    }

    private function percent(float $ratio): string
    {
        return number_format($ratio * 100, $ratio < 0.1 ? 1 : 0).'%';
    }
}
