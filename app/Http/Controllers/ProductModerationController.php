<?php

namespace App\Http\Controllers;

use App\Contracts\CategoryTree;
use App\Http\Controllers\Operator\Concerns\AnswersDecisions;
use App\Models\ContentReport;
use App\Models\Product;
use App\Models\StoreCategory;
use App\Services\Operator\ListingFlags;
use App\Services\Operator\OperatorAudit;
use App\Services\Operator\OperatorDecisions;
use App\Services\Stores\StoreTrust;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The listing review queue (/operator/products): what is waiting, the listing in full, and approve or reject. A rejection needs a
 * reason, is saved with its audit entry, and the seller is shown the reason on the listing and in a notice (OperatorDecisions).
 */
class ProductModerationController extends Controller
{
    use AnswersDecisions;

    /** Reasons an operator can start from; the operator edits the text, and the seller reads the final wording. */
    public const REASON_TEMPLATES = [
        'photos' => 'The photographs do not show the product clearly. Add at least one clear, well-lit photo of the item itself, then resubmit.',
        'description' => 'The description is too thin to judge what is being sold. Say what it is, its size or specification, and what is in the box.',
        'category' => 'The listing is in the wrong category. Move it to the category shoppers would look in, then resubmit.',
        'price' => 'The price looks like a typing slip. Check it and resubmit.',
        'prohibited' => 'We do not allow this kind of item on Dot.Emall yet. See the seller help for what can be sold.',
        'counterfeit' => 'We could not confirm this is a genuine, authorised product. Add proof that you may sell this brand (an invoice or a supplier letter) and resubmit.',
    ];

    public function index(Request $request): View
    {
        $status = in_array($request->query('status'), ['pending_approval', 'approved', 'rejected', 'all'], true) ? $request->query('status') : 'pending_approval';
        $q = trim((string) $request->query('q', ''));

        $products = Product::query()
            ->with(['store:id,name,owner_id,created_at', 'category:id,name', 'images'])
            ->when($status !== 'all', fn ($w) => $w->where('status', $status))
            ->when($q !== '', fn ($w) => $w->where('name', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%'))
            ->when($request->integer('store'), fn ($w, $store) => $w->where('store_id', $store))
            ->when($request->integer('category'), fn ($w, $category) => $w->whereIn('category_id', app(CategoryTree::class)->withDescendants($category)))
            ->when($status === 'pending_approval', fn ($w) => $w->oldest(), fn ($w) => $w->latest('reviewed_at')->latest('id'))
            ->paginate(20)->withQueryString();

        $counts = Product::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return view('operator.products.index', [
            'products' => $products,
            'status' => $status,
            'counts' => $counts,
            'filters' => ['q' => $q, 'store' => $request->integer('store') ?: null, 'category' => $request->integer('category') ?: null],
            'categories' => StoreCategory::query()->whereNull('parent_id')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(Product $product, ListingFlags $flags, OperatorAudit $audit): View
    {
        $product->load(['store.owner:id,name,email', 'category', 'brand', 'images', 'variants', 'attributeValues', 'options']);

        return view('operator.products.show', [
            'product' => $product,
            'trust' => StoreTrust::for($product->store),
            'flags' => $flags->all()->filter(fn ($f) => $f['product']->id === $product->id),
            'reports' => ContentReport::query()->where('reportable_type', 'product')->where('reportable_id', $product->id)->where('status', 'open')->count(),
            'history' => $audit->query(subjectType: 'product', subjectId: $product->id)->limit(10)->get(),
            'templates' => self::REASON_TEMPLATES,
        ]);
    }

    public function approve(Product $product, Request $request, OperatorDecisions $decisions): RedirectResponse
    {
        return $this->attempt('approve-'.$product->id, fn () => $decisions->approveListing($product, $request->user()), 'Listing approved. The seller has been told.');
    }

    public function reject(Product $product, Request $request, OperatorDecisions $decisions): RedirectResponse
    {
        $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        return $this->attempt('reject-'.$product->id, fn () => $decisions->rejectListing($product, $request->user(), $request->input('reason')), 'Listing rejected. The seller has been told why.');
    }
}
