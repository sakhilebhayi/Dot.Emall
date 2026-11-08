<?php

namespace App\Support;

use App\Models\Collection;
use App\Services\Catalogue\CategoryTree;

/** Plain-language lines about a collection: its dates as recorded, and its rule in words. */
final class CollectionText
{
    /** The dates, as they are recorded and nothing more. */
    public static function window(Collection $c): string
    {
        $fmt = fn ($d) => $d->format('j F Y').($d->format('H:i') !== '00:00' ? ' at '.$d->format('H:i') : '');

        return match (true) {
            $c->starts_at && $c->ends_at => ($c->ends_at->lte(now()) ? 'Ran from ' : 'Runs from ').$fmt($c->starts_at).' to '.$fmt($c->ends_at),
            $c->ends_at !== null => ($c->ends_at->lte(now()) ? 'Ended on ' : 'Ends on ').$fmt($c->ends_at),
            $c->starts_at !== null => 'Started on '.$fmt($c->starts_at).', with no end date set',
            default => 'No start or end date is set',
        };
    }

    /** @param  array<string, mixed>  $rule */
    public static function rule(array $rule, CategoryTree $tree): string
    {
        $parts = [];

        if (! empty($rule['q'])) {
            $parts[] = 'matching "'.$rule['q'].'"';
        }

        if (! empty($rule['category'])) {
            $cat = ctype_digit((string) $rule['category']) ? $tree->find((int) $rule['category']) : $tree->findBySlug((string) $rule['category']);
            $parts[] = 'in '.($cat['name'] ?? 'a category');
        }

        if (! empty($rule['brand'])) {
            $parts[] = 'brand '.implode(' or ', (array) $rule['brand']);
        }

        $min = $rule['price_min'] ?? null;
        $max = $rule['price_max'] ?? null;

        if ($min !== null && $max !== null) {
            $parts[] = 'priced R'.number_format((float) $min, 0).' to R'.number_format((float) $max, 0);
        } elseif ($max !== null) {
            $parts[] = 'priced up to R'.number_format((float) $max, 0);
        } elseif ($min !== null) {
            $parts[] = 'priced from R'.number_format((float) $min, 0);
        }

        if (! empty($rule['in_stock'])) {
            $parts[] = 'in stock';
        }

        if (! empty($rule['rating'])) {
            $parts[] = 'rated '.$rule['rating'].' stars and up';
        }

        foreach ((array) ($rule['attr'] ?? []) as $key => $values) {
            $parts[] = str_replace('_', ' ', (string) $key).' '.implode(' or ', (array) $values);
        }

        if (! empty($rule['region'])) {
            $parts[] = 'sold from '.implode(' or ', (array) $rule['region']);
        }

        return $parts ? implode(', ', $parts) : 'all listings';
    }
}
