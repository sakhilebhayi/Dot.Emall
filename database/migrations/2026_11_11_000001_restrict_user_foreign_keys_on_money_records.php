<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R-43 D-98. Deleting a user used to cascade through other people's records: a buyer who deleted their account deleted
 * their orders, order lines, returns, refunds and payment records with them, and a seller who did so deleted their
 * store and (through it) every order line that was bought from it. Other keys quietly set the seller to NULL on the
 * ledger and on the parcels, so the money trail lost its owner.
 *
 * These keys now RESTRICT: a user row that still owns an order, a store, a ledger entry, a parcel, a return, a review
 * or a question cannot be deleted at all. The application never deletes such a user; it anonymises them
 * (App\Services\Account\AccountEraser), so this is the backstop for any other code path or a hand-run DELETE.
 *
 * Portable: the keys are found by column and only changed when they are not RESTRICT already (a re-run is a no-op),
 * on PostgreSQL by dropping and re-adding the constraint, on SQLite by the table rebuild Laravel performs. A rebuild
 * recreates indexes from what Laravel knows about them, so every table's index list is read first and any index the
 * rebuild lost is put back (none of these tables has a partial or expression index today; this keeps it true if one is
 * added). Existing rows already satisfy the constraint, so nothing can fail on existing data. `down()` restores the
 * previous action of each key (cascade or set null).
 *
 * Deliberately unchanged: addresses, cart items, wishlists, saved searches, alerts, notification choices and review
 * votes stay CASCADE (a person's own private rows, which the eraser deletes anyway), and the operator and audit
 * columns (actor_id, handled_by, reviewed_by, resolved_by, ...) stay SET NULL.
 */
return new class extends Migration
{
    /**
     * table => [column => the action the key had before this migration, restored by down()]
     *
     * @var array<string, array<string, string>>
     */
    private const KEYS = [
        'orders' => ['user_id' => 'cascade'],
        'stores' => ['owner_id' => 'cascade'],
        'order_groups' => ['seller_id' => 'set null'],
        'order_items' => ['seller_id' => 'set null'],
        'ledger_entries' => ['seller_id' => 'set null'],
        'return_requests' => ['requester_id' => 'set null', 'seller_id' => 'set null'],
        'reviews' => ['user_id' => 'cascade'],
        'review_responses' => ['user_id' => 'cascade'],
        'product_questions' => ['user_id' => 'cascade'],
        'product_answers' => ['user_id' => 'cascade'],
        'content_reports' => ['reporter_id' => 'cascade'],
        'seller_order_notes' => ['user_id' => 'cascade'],
    ];

    public function up(): void
    {
        foreach (self::KEYS as $table => $columns) {
            $this->change($table, array_map(fn () => 'restrict', $columns));
        }
    }

    public function down(): void
    {
        foreach (self::KEYS as $table => $columns) {
            $this->change($table, $columns);
        }
    }

    /**
     * @param  array<string, string>  $actions  column => the ON DELETE action it should have
     */
    private function change(string $table, array $actions): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $todo = [];

        foreach ($actions as $column => $action) {
            $current = $this->currentAction($table, $column);

            // No key (an older database), or already right: leave it alone.
            if ($current !== null && $current !== $action && ! ($action === 'restrict' && $current === 'no action')) {
                $todo[$column] = $action;
            }
        }

        if ($todo === []) {
            return;
        }

        $before = $this->indexes($table);

        Schema::table($table, function (Blueprint $t) use ($todo) {
            foreach ($todo as $column => $action) {
                $t->dropForeign([$column]);

                $foreign = $t->foreign($column)->references('id')->on('users');

                match ($action) {
                    'restrict' => $foreign->restrictOnDelete(),
                    'set null' => $foreign->nullOnDelete(),
                    default => $foreign->cascadeOnDelete(),
                };
            }
        });

        $this->restoreLostIndexes($table, $before);
    }

    /**
     * @return array<string, array{columns: array<int, string>, unique: bool}>
     */
    private function indexes(string $table): array
    {
        $out = [];

        foreach (Schema::getIndexes($table) as $index) {
            if (! empty($index['primary'])) {
                continue;
            }

            $out[$index['name']] = ['columns' => $index['columns'], 'unique' => (bool) $index['unique']];
        }

        return $out;
    }

    /**
     * @param  array<string, array{columns: array<int, string>, unique: bool}>  $before
     */
    private function restoreLostIndexes(string $table, array $before): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        $after = $this->indexes($table);

        foreach (array_diff_key($before, $after) as $name => $index) {
            Schema::table($table, function (Blueprint $t) use ($name, $index) {
                $index['unique'] ? $t->unique($index['columns'], $name) : $t->index($index['columns'], $name);
            });
        }
    }

    /**
     * The ON DELETE action of the foreign key on $table.$column ('cascade', 'restrict', 'no action', 'set null'),
     * or null when there is none.
     */
    private function currentAction(string $table, string $column): ?string
    {
        foreach (Schema::getForeignKeys($table) as $key) {
            if ($key['columns'] === [$column]) {
                return strtolower((string) $key['on_delete']);
            }
        }

        return null;
    }
};
