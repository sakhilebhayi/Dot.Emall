<?php

namespace App\Http\Controllers\Discovery;

use App\Http\Controllers\Controller;
use App\Models\StoreCategory;
use App\Services\Catalogue\CategoryTree;
use Illuminate\View\View;

/** The category tree with real counts: only categories a shopper can buy from, a parent counting everything beneath it. */
class CategoryController extends Controller
{
    public function index(CategoryTree $tree): View
    {
        $roots = array_values(array_filter($tree->tree(), fn (array $root) => $root['rollup_count'] > 0));
        $descriptions = StoreCategory::query()->whereIn('id', array_column($roots, 'id'))->pluck('description', 'id');
        $total = array_sum(array_column($roots, 'rollup_count'));

        return view('discover.categories', ['roots' => $roots, 'descriptions' => $descriptions, 'total' => $total]);
    }
}
