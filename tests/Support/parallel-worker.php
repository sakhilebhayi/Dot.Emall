<?php

/*
 * Child process for ParallelCheckoutPostgresTest: boots the application, waits for a shared start time, performs ONE
 * action and prints the outcome as a line of JSON. It exists only to give PostgreSQL several real, simultaneous
 * connections (SQLite serialises writers and ignores row locks, so the locking can only be shown here).
 *
 *   php tests/Support/parallel-worker.php checkout <userId> <startAt> [promoCode]
 *   php tests/Support/parallel-worker.php charge   <reference> <startAt> <amountCents> <transactionId> <source>
 *   php tests/Support/parallel-worker.php cancel   <orderItemId> <startAt>
 *   php tests/Support/parallel-worker.php stock    <productId> <startAt> <newStock>
 *
 * Not a test and not autoloaded.
 */

use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\CheckoutException;
use App\Services\CheckoutService;
use App\Services\OrderLifecycle;
use App\Services\PaymentService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$mode = $argv[1];
$startAt = (float) $argv[3];

$wait = function () use ($startAt) {
    while (microtime(true) < $startAt) {
        usleep(200);
    }
};

try {
    if ($mode === 'checkout') {
        $user = User::findOrFail((int) $argv[2]);
        $wait();
        $order = $app->make(CheckoutService::class)->place($user, [
            'shipping_address' => '1 Test Street, Testville',
            'promo_code' => $argv[4] ?? null,
        ], (string) Str::uuid(), '127.0.0.1');
        echo json_encode(['ok' => true, 'order' => $order->id, 'total' => (string) $order->total]), "\n";
    } elseif ($mode === 'charge') {
        $wait();
        $outcome = $app->make(PaymentService::class)->recordSuccessfulCharge($argv[2], (int) $argv[4], 'ZAR', $argv[5], ['status' => 'success'], $argv[6]);
        echo json_encode(['ok' => true, 'outcome' => $outcome]), "\n";
    } elseif ($mode === 'cancel') {
        $line = OrderItem::findOrFail((int) $argv[2]);
        $wait();
        $app->make(OrderLifecycle::class)->cancelItem($line);
        echo json_encode(['ok' => true]), "\n";
    } elseif ($mode === 'stock') {
        // What the seller's edit form does: write the number from the form.
        $product = Product::findOrFail((int) $argv[2]);
        $wait();
        $product->update(['stock' => (int) $argv[4]]);
        echo json_encode(['ok' => true]), "\n";
    }
} catch (CheckoutException $e) {
    echo json_encode(['ok' => false, 'why' => $e->getMessage()]), "\n";
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'crash' => get_class($e), 'why' => substr($e->getMessage(), 0, 200)]), "\n";
}
