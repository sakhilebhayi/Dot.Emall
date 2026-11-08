<?php

namespace App\Services\Seller;

use App\Services\Catalogue\CategoryTree;

/** The category tree flattened for a <select>: "Electronics", then "  Laptops" indented by depth, active categories only. */
class CategoryOptions
{
    public function __construct(private readonly CategoryTree $tree) {}

    /** @return array<int, string> category id => label */
    public function flat(): array
    {
        $out = [];
        $walk = function (array $nodes, int $depth) use (&$walk, &$out) {
            foreach ($nodes as $node) {
                $out[$node['id']] = str_repeat("\u{2003}", $depth).$node['name'];
                $walk($node['children'] ?? [], $depth + 1);
            }
        };
        $walk($this->tree->tree(), 0);

        return $out;
    }
}
