<?php

namespace App\Models;

use App\Services\Search\Synonyms;
use App\Services\Search\Text;
use Illuminate\Database\Eloquent\Model;

/**
 * One word in a group of equivalent words. Words are stored in the searched form (lowercase, accent-free, folded),
 * whatever the operator typed.
 */
class SearchSynonym extends Model
{
    protected $fillable = ['group_key', 'term', 'note'];

    protected static function booted(): void
    {
        static::saving(function (SearchSynonym $synonym) {
            $synonym->term = Text::index((string) $synonym->term);
        });

        static::saved(fn () => Synonyms::forget());
        static::deleted(fn () => Synonyms::forget());
    }
}
