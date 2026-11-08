<?php

namespace App\Services\Search;

use Illuminate\Support\Facades\DB;

/**
 * The starting set of equivalent words, including South African usage (takkies, braai, bakkie, geyser, cooldrink,
 * stoep). Each group lists words that mean the same thing to a shopper. Operators can add and remove rows in
 * search_synonyms; seed() only adds what is missing, so it never overwrites an operator's edit and is safe to re-run.
 */
final class DefaultSynonyms
{
    /** @var array<string, list<string>> */
    public const GROUPS = [
        'laptop' => ['laptop', 'notebook', 'portable computer'],
        'television' => ['tv', 'television', 'telly', 'smart tv'],
        'cellphone' => ['cellphone', 'cell phone', 'cell', 'mobile', 'mobile phone', 'smartphone', 'smart phone', 'phone'],
        'fridge' => ['fridge', 'refrigerator', 'fridge freezer'],
        'sneakers' => ['sneakers', 'trainers', 'takkies', 'tekkies', 'running shoes'],
        'braai' => ['braai', 'bbq', 'barbecue', 'barbeque'],
        'bakkie' => ['bakkie', 'pickup', 'pick up truck'],
        'geyser' => ['geyser', 'water heater', 'hot water cylinder'],
        'sofa' => ['sofa', 'couch', 'settee'],
        'jersey' => ['jersey', 'jumper', 'sweater', 'pullover'],
        'trousers' => ['trousers', 'pants'],
        'nappy' => ['nappy', 'diaper'],
        'dummy' => ['dummy', 'pacifier', 'soother'],
        'cooldrink' => ['cooldrink', 'soft drink', 'soda', 'fizzy drink'],
        'powerbank' => ['powerbank', 'power bank', 'portable charger'],
        'earphones' => ['earphones', 'earbuds', 'in ear headphones'],
        'adapter' => ['adapter', 'adaptor', 'plug adapter'],
        'inverter' => ['inverter', 'ups', 'backup power', 'load shedding backup'],
        'aircon' => ['aircon', 'air conditioner', 'air con', 'ac unit'],
        'washing machine' => ['washing machine', 'washer', 'laundry machine'],
        'stove' => ['stove', 'cooker', 'oven and hob'],
        'dryer' => ['dryer', 'tumble dryer', 'tumble drier'],
        'phone case' => ['phone case', 'phone cover', 'cellphone cover', 'cellphone case'],
        'kids' => ['kids', 'children', 'childrens'],
        'wifi' => ['wifi', 'wi fi', 'wireless internet'],
    ];

    /** Adds any missing default rows. Returns how many rows were added. */
    public static function seed(): int
    {
        $added = 0;
        $now = now();

        foreach (self::GROUPS as $group => $terms) {
            foreach ($terms as $term) {
                $normalised = Text::index($term);

                $exists = DB::table('search_synonyms')->where('group_key', $group)->where('term', $normalised)->exists();

                if (! $exists) {
                    DB::table('search_synonyms')->insert(['group_key' => $group, 'term' => $normalised, 'created_at' => $now, 'updated_at' => $now]);
                    $added++;
                }
            }
        }

        return $added;
    }
}
