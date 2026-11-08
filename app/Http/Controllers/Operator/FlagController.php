<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Operator\Concerns\AnswersDecisions;
use App\Models\Product;
use App\Services\Operator\ListingFlags;
use App\Services\Operator\OperatorDecisions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Listings the simple rules flagged: a review aid. The rule and the evidence are shown; a person decides. */
class FlagController extends Controller
{
    use AnswersDecisions;

    public function index(Request $request, ListingFlags $flags): View
    {
        $rule = array_key_exists((string) $request->query('rule'), ListingFlags::RULES) ? (string) $request->query('rule') : null;
        $all = $flags->all();

        return view('operator.flags.index', [
            'flags' => $rule ? $all->where('rule', $rule)->values() : $all,
            'rule' => $rule,
            'ruleCounts' => $all->countBy('rule'),
            'rules' => $flags->describe(),
        ]);
    }

    public function dismiss(Request $request, Product $product, OperatorDecisions $decisions): RedirectResponse
    {
        $data = $request->validate(['rule' => ['required', 'in:'.implode(',', array_keys(ListingFlags::RULES))], 'note' => ['nullable', 'string', 'max:500']]);

        return $this->attempt('flag-'.$product->id.'-'.$data['rule'], fn () => $decisions->dismissFlag($product, $data['rule'], $request->user(), $data['note'] ?? null), 'Marked as fine. It will not be flagged for that rule again.');
    }
}
