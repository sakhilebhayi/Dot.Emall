@props(['status', 'size' => 'md'])

@php
    $value = $status instanceof \App\Enums\OrderStatus ? $status->value : (string) $status;

    [$bg, $fg] = match ($value) {
        'pending'    => ['rgba(245,158,11,0.12)', '#f59e0b'],
        'processing' => ['rgba(96,165,250,0.12)', '#60a5fa'],
        'shipped'    => ['rgba(34,211,238,0.12)', '#22d3ee'],
        'delivered'  => ['rgba(34,197,94,0.12)', '#22c55e'],
        'cancelled'  => ['rgba(239,68,68,0.12)', '#ef4444'],
        'paid'       => ['rgba(34,197,94,0.12)', '#22c55e'],
        'awaiting payment' => ['rgba(245,158,11,0.12)', '#f59e0b'],
        'payment failed'   => ['rgba(239,68,68,0.12)', '#ef4444'],
        default      => ['rgba(107,114,128,0.12)', '#9ca3af'],
    };

    $pad = $size === 'sm' ? '0.2rem 0.65rem' : '0.3rem 0.85rem';
    $font = $size === 'sm' ? '0.65rem' : '0.72rem';
@endphp

<span {{ $attributes->merge(['style' => "display:inline-block;padding:{$pad};border-radius:9999px;background:{$bg};font-size:{$font};font-weight:700;color:{$fg};text-transform:capitalize;white-space:nowrap;"]) }}>{{ $value }}</span>
