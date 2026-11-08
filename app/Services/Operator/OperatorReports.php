<?php

namespace App\Services\Operator;

use App\Models\ContentReport;
use App\Models\Product;
use App\Models\ProductAnswer;
use App\Models\ProductQuestion;
use App\Models\Review;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reports on reviews, questions, answers, listings and stores, for the operator: grouped by the thing reported, with every reason and
 * reporter count, the outcome note once settled, and a repeat-offender view (people whose posts were actioned more than once lately).
 * Reads only; decisions go through OperatorDecisions.
 */
class OperatorReports
{
    /**
     * @return Collection<int, array{type: string, id: int, reports: int, reasons: array<string,int>, first_at: string, last_at: string, status: string, label: string, excerpt: ?string, url: ?string, resolution: ?string, handled_at: ?string}>
     */
    public function groups(string $status = 'open', ?string $type = null, ?string $reason = null, int $limit = 100): Collection
    {
        $statuses = $status === 'settled' ? ['actioned', 'dismissed'] : [$status === 'all' ? null : $status];

        $rows = ContentReport::query()
            ->when($status !== 'all', fn ($q) => $q->whereIn('status', array_filter($statuses)))
            ->when($type, fn ($q) => $q->where('reportable_type', $type))
            ->when($reason, fn ($q) => $q->where('reason', $reason))
            ->orderBy('id')->limit(2000)->get(['id', 'reportable_type', 'reportable_id', 'reporter_id', 'reason', 'status', 'resolution', 'handled_at', 'created_at']);

        $groups = $rows->groupBy(fn ($r) => $r->reportable_type.':'.$r->reportable_id)->map(function (Collection $g) {
            $first = $g->first();
            $last = $g->sortByDesc('id')->first();

            return [
                'type' => $first->reportable_type,
                'id' => (int) $first->reportable_id,
                'reports' => $g->count(),
                'reporters' => $g->pluck('reporter_id')->unique()->count(),
                'reasons' => $g->countBy('reason')->sortDesc()->all(),
                'first_at' => (string) $g->min('created_at'),
                'last_at' => (string) $g->max('created_at'),
                'status' => $g->contains('status', 'open') ? 'open' : (string) $last->status,
                'resolution' => $g->contains('status', 'open') ? null : $last->resolution,
                'handled_at' => $g->contains('status', 'open') ? null : ($last->handled_at ? (string) $last->handled_at : null),
            ];
        })->sortBy([['reporters', 'desc'], ['first_at', 'asc']])->take($limit)->values();

        return $groups->map(fn (array $g) => $g + $this->describe($g['type'], $g['id']));
    }

    /**
     * Everything one item has been reported for: each report with its reporter, reason and details, newest first.
     *
     * @return Collection<int, ContentReport>
     */
    public function reportsFor(string $type, int $id): Collection
    {
        return ContentReport::query()->where('reportable_type', $type)->where('reportable_id', $id)->with(['reporter:id,name', 'handler:id,name'])->orderByDesc('id')->get();
    }

    /**
     * People with more than one post, listing or store actioned on report in the window. Counted per item, so five reports of one post count once.
     *
     * @return Collection<int, array{user: User, items: int, types: list<string>, last_at: string}>
     */
    public function repeatOffenders(int $days = 90, int $minimum = 2): Collection
    {
        $actioned = ContentReport::query()->where('status', 'actioned')->where('handled_at', '>=', now()->subDays($days))
            ->get(['reportable_type', 'reportable_id', 'handled_at'])
            ->unique(fn ($r) => $r->reportable_type.':'.$r->reportable_id);

        if ($actioned->isEmpty()) {
            return collect();
        }

        $authors = [];

        foreach ($actioned->groupBy('reportable_type') as $type => $items) {
            $ids = $items->pluck('reportable_id')->all();
            $map = match ($type) {
                'review' => Review::query()->whereIn('id', $ids)->pluck('user_id', 'id'),
                'question' => ProductQuestion::query()->whereIn('id', $ids)->pluck('user_id', 'id'),
                'answer' => ProductAnswer::query()->whereIn('id', $ids)->pluck('user_id', 'id'),
                'product' => Product::query()->withTrashed()->join('stores', 'stores.id', '=', 'products.store_id')->whereIn('products.id', $ids)->pluck('stores.owner_id', 'products.id'),
                'store' => Store::query()->whereIn('id', $ids)->pluck('owner_id', 'id'),
                default => collect(),
            };

            foreach ($items as $item) {
                if ($owner = $map[$item->reportable_id] ?? null) {
                    $authors[$owner][] = ['type' => $type, 'at' => (string) $item->handled_at];
                }
            }
        }

        $users = User::query()->whereIn('id', array_keys($authors))->get(['id', 'name', 'email'])->keyBy('id');

        return collect($authors)->filter(fn ($rows) => count($rows) >= $minimum)->map(fn ($rows, $uid) => [
            'user' => $users[$uid],
            'items' => count($rows),
            'types' => collect($rows)->pluck('type')->unique()->values()->all(),
            'last_at' => collect($rows)->max('at'),
        ])->filter(fn ($r) => $r['user'] !== null)->sortByDesc('items')->values();
    }

    /** @return array{label: string, excerpt: ?string, url: ?string} */
    public function describe(string $type, int $id): array
    {
        return match ($type) {
            'review' => ($r = Review::query()->with('product:id,name')->find($id))
                ? ['label' => 'Review of '.($r->product?->name ?? 'a product'), 'excerpt' => $this->cut($r->body), 'url' => $r->product ? route('products.show', $r->product) : null]
                : ['label' => 'Review #'.$id, 'excerpt' => null, 'url' => null],
            'question' => ($q = ProductQuestion::query()->with('product:id,name')->find($id))
                ? ['label' => 'Question on '.($q->product?->name ?? 'a product'), 'excerpt' => $this->cut($q->body), 'url' => $q->product ? route('products.show', $q->product) : null]
                : ['label' => 'Question #'.$id, 'excerpt' => null, 'url' => null],
            'answer' => ($a = ProductAnswer::query()->with('question.product:id,name')->find($id))
                ? ['label' => 'Answer on '.($a->question?->product?->name ?? 'a product'), 'excerpt' => $this->cut($a->body), 'url' => $a->question?->product ? route('products.show', $a->question->product) : null]
                : ['label' => 'Answer #'.$id, 'excerpt' => null, 'url' => null],
            'product' => ($p = Product::query()->withTrashed()->find($id))
                ? ['label' => 'Listing: '.$p->name, 'excerpt' => $this->cut($p->description), 'url' => route('operator.products.show', $p)]
                : ['label' => 'Listing #'.$id, 'excerpt' => null, 'url' => null],
            'store' => ($s = Store::query()->find($id))
                ? ['label' => 'Store: '.$s->name, 'excerpt' => $this->cut($s->description), 'url' => route('operator.stores.show', $s)]
                : ['label' => 'Store #'.$id, 'excerpt' => null, 'url' => null],
            default => ['label' => ucfirst($type).' #'.$id, 'excerpt' => null, 'url' => null],
        };
    }

    /** @return array<string, int> status => reports, for the filter chips */
    public function counts(): array
    {
        return ContentReport::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();
    }

    private function cut(?string $text): ?string
    {
        return $text !== null && trim($text) !== '' ? mb_substr(trim($text), 0, 240) : null;
    }
}
