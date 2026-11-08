<?php

namespace App\Services\Seller;

use App\Models\LedgerEntry;
use App\Models\Store;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV files of a seller's OWN data (W6). Orders are one row per line, with no buyer name, address, phone or e-mail: a
 * seller needs those only to post a parcel, and a spreadsheet is where personal data wanders. A cell that starts with = + - or @
 * is prefixed with an apostrophe, so a product named "=HYPERLINK(...)" cannot run as a formula when the file is opened.
 */
class SellerExports
{
    public function __construct(private readonly SellerAnalytics $analytics) {}

    public function orders(Store $store, Carbon $from, Carbon $to): StreamedResponse
    {
        $rows = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->where('oi.store_id', $store->id)->whereBetween('o.created_at', [$from, $to])
            ->orderBy('oi.id')
            ->get(['o.id as order_id', 'o.created_at', 'o.payment_status', 'o.currency', 'oi.product_name', 'oi.variant_label', 'oi.sku', 'oi.quantity', 'oi.unit_price', 'oi.total', 'oi.discount_amount', 'oi.status']);

        return $this->stream('emall-orders-'.$from->toDateString().'-to-'.$to->toDateString().'.csv',
            ['Order', 'Placed', 'Payment', 'Item', 'Option', 'SKU', 'Quantity', 'Unit price', 'Line total', 'Discount', 'Net of discount', 'Currency', 'Item status'],
            $rows->map(fn ($r) => [
                '#'.str_pad((string) $r->order_id, 5, '0', STR_PAD_LEFT), Carbon::parse($r->created_at)->format('Y-m-d H:i'), $r->payment_status, $r->product_name, $r->variant_label, $r->sku,
                $r->quantity, $this->money($r->unit_price), $this->money($r->total), $this->money($r->discount_amount),
                Money::fromCents(max(0, Money::toCents($r->total) - Money::toCents($r->discount_amount))), $r->currency, $r->status,
            ])->all());
    }

    public function products(Store $store, Carbon $from, Carbon $to): StreamedResponse
    {
        $performance = $this->analytics->products($store, $from, $to)->keyBy(fn ($r) => $r['product']->id);

        $rows = DB::table('products')->where('store_id', $store->id)->whereNull('deleted_at')->orderBy('id')
            ->get(['id', 'name', 'sku', 'status', 'is_active', 'price', 'stock']);

        return $this->stream('emall-products-'.$from->toDateString().'-to-'.$to->toDateString().'.csv',
            ['Product', 'SKU', 'Listing status', 'Switched on', 'Price', 'Stock', 'Views', 'Added to cart', 'Orders', 'Units', 'Sales', 'Orders per view'],
            $rows->map(function ($r) use ($performance) {
                $p = $performance[$r->id] ?? null;

                return [$r->name, $r->sku, $r->status, $r->is_active ? 'yes' : 'no', $this->money($r->price), $r->stock,
                    $p['views'] ?? 0, $p['carts'] ?? 0, $p['orders'] ?? 0, $p['units'] ?? 0, Money::fromCents($p['sales_cents'] ?? 0),
                    isset($p['orders_per_view']) ? $p['orders_per_view'].'%' : ''];
            })->all());
    }

    public function ledger(Store $store): StreamedResponse
    {
        $rows = LedgerEntry::query()->where('store_id', $store->id)->orderBy('id')->get();

        return $this->stream('emall-ledger.csv',
            ['Entry', 'Recorded', 'Order', 'Kind', 'Status', 'Gross', 'Fee percent', 'Fee', 'Net', 'Release date', 'Released', 'Reason'],
            $rows->map(fn (LedgerEntry $e) => [
                $e->id, $e->created_at?->format('Y-m-d H:i'), '#'.str_pad((string) $e->order_id, 5, '0', STR_PAD_LEFT), $e->kind, $e->status,
                Money::fromCents($e->gross_cents), $e->commission_percent, Money::fromCents($e->commission_cents), Money::fromCents($e->net_cents),
                $e->release_at?->toDateString(), $e->released_at?->format('Y-m-d H:i'), $e->reason,
            ])->all());
    }

    /**
     * @param  array<int, string>  $header
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function stream(string $name, array $header, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // so a spreadsheet reads the file as UTF-8
            fputcsv($out, $header);
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($cell) => $this->safe($cell), $row));
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    private function safe(mixed $cell): string
    {
        $cell = (string) ($cell ?? '');

        return $cell !== '' && in_array($cell[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($cell) ? "'".$cell : $cell;
    }

    private function money(mixed $decimal): string
    {
        return Money::fromCents(Money::toCents($decimal));
    }
}
