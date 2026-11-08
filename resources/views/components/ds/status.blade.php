{{--
  ds.status: the status vocabulary (App\Support\Status, DESIGN.md). A word, a shape and a tone, never colour alone.
  Props: kind='order' | 'product', state (e.g. awaiting_payment, paid, packing, shipped, delivered, cancelled, refunded, return_requested, return_approved,
         return_declined, payment_failed; in_stock, low_stock, out_of_stock, unlisted, pending_review, rejected; the first version's words such as 'pending' also work),
         quantity=null (low_stock prints "Only N left"; the component never invents a number: pass the real remaining quantity).
--}}
@props(['kind' => 'order', 'state', 'quantity' => null])
@php($s = \App\Support\Status::resolve($kind, (string) $state, $quantity === null ? null : (int) $quantity))
<x-ds.badge :tone="$s['tone']" :icon="$s['icon']" {{ $attributes->merge(['data-status' => $s['state']]) }}>{{ $s['word'] }}</x-ds.badge>
