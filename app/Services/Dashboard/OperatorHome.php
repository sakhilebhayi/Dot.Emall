<?php

namespace App\Services\Dashboard;

use App\Models\Product;
use App\Services\Operator\NeedsAPerson;
use App\Services\Operator\OperatorAudit;
use Illuminate\Support\Carbon;

/**
 * What the operator's first page shows (R-51): what is waiting for a decision, longest wait first, and what was last decided. The queues
 * are NeedsAPerson (platform-wide, an operator's job); the primary action opens the queue that has waited longest.
 */
class OperatorHome
{
    /** The short name each queue takes in the primary action: "Decide the oldest: listings". */
    private const SHORT = [
        'products' => 'listings', 'stores' => 'stores', 'returns' => 'returns', 'reports' => 'reports', 'reviews' => 'reviews',
        'questions' => 'questions', 'payments' => 'payments', 'refunds' => 'refunds', 'ledger' => 'ledger mismatches', 'flags' => 'flagged listings',
    ];

    public function __construct(private readonly NeedsAPerson $needs, private readonly OperatorAudit $audit) {}

    /** @return array<string, mixed> */
    public function data(): array
    {
        $items = $this->needs->sorted();
        $waiting = array_values(array_filter($items, fn ($i) => $i['state'] === 'waiting'));
        $unknown = array_values(array_filter($items, fn ($i) => $i['state'] === 'unknown'));
        $clear = array_values(array_filter($items, fn ($i) => $i['state'] === 'clear'));
        $total = (int) array_sum(array_map(fn ($i) => (int) $i['count'], $waiting));

        return [
            'waiting' => $waiting,
            'unknown' => $unknown,
            'clear' => $clear,
            'recent' => $this->audit->query()->limit(6)->get(),
            'total' => $total,
            'thumbs' => $this->pendingThumbs(),
            'headline' => $total === 0 ? 'Nothing is waiting for you.' : ($total === 1 ? '1 thing is waiting for a decision.' : $total.' things are waiting for a decision.'),
            'sentence' => self::sentence($waiting),
            'primary' => self::primary($waiting),
            'foot' => [$total.' waiting', Plural::of(count($clear), 'queue').' clear', count($unknown).' not known'],
        ];
    }

    /** @param  list<array<string, mixed>>  $waiting */
    public static function sentence(array $waiting): string
    {
        $oldest = collect($waiting)->first(fn ($i) => $i['oldest'] !== null);

        if ($oldest === null) {
            return $waiting === []
                ? 'Every queue is empty. New listings, stores, reports and returns appear here the moment they need a decision.'
                : 'Longest wait first. Each line opens its queue.';
        }

        return 'Longest wait first. The oldest is '.Plural::of((int) $oldest['count'], 'item').' in "'.$oldest['label'].'", waiting '.Carbon::parse($oldest['oldest'])->diffForHumans(null, true).'.';
    }

    /** @param  list<array<string, mixed>>  $waiting @return array{label: string, href: string, route: string, state: string} */
    public static function primary(array $waiting): array
    {
        $first = $waiting[0] ?? null;

        if ($first === null) {
            return ['label' => 'Open the audit trail', 'href' => route('operator.audit'), 'route' => 'operator.audit', 'state' => 'clear'];
        }

        return ['label' => 'Decide the oldest: '.(self::SHORT[$first['key']] ?? strtolower($first['label'])), 'href' => route($first['route'], $first['params'] ?? []), 'route' => $first['route'], 'state' => 'decide'];
    }

    /** The cover photos of the oldest listings still awaiting approval (platform-wide, as the queue is): up to three. @return list<array{name: string, url: string}> */
    private function pendingThumbs(): array
    {
        return Product::query()->where('status', 'pending_approval')->whereHas('images')->with('images')->oldest('created_at')->limit(3)->get()
            ->map(fn (Product $p) => ['name' => $p->name, 'url' => $p->images->sortBy('sort_order')->first()->cardUrl()])->all();
    }
}
