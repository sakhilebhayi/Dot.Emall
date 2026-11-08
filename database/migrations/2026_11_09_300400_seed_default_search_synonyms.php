<?php

use App\Services\Search\DefaultSynonyms;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /** Adds the default equivalent words (laptop/notebook, takkies/sneakers, braai/bbq ...). Never overwrites an edit. */
    public function up(): void
    {
        DefaultSynonyms::seed();
    }

    public function down(): void
    {
        // Operators may have added their own; removing rows on rollback would lose them.
    }
};
