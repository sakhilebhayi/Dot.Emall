{{--
  ds.review-card: one review. "Verified purchase" is printed only when the buyer has a delivered order line for the product (pass verified=true only then).
  Props: author (required), rating (required, 1 to 5), body (required), title=null, date=null, verified=false, reply=null (['from' => store name, 'body' =>, 'date' =>]).
  Slot: footer (helpful votes, report).
--}}
@props(['author', 'rating', 'body', 'title' => null, 'date' => null, 'verified' => false, 'reply' => null])
<article {{ $attributes->class('ds-review') }}>
    <div class="ds-review__head">
        <x-ds.rating :value="$rating" :count="1" :show-score="false" :show-count="false" />
        @if ($title)<h3 class="ds-review__title">{{ $title }}</h3>@endif
    </div>
    <p class="ds-review__meta">{{ $author }}@if ($date), {{ $date instanceof \DateTimeInterface ? $date->format('j M Y') : $date }}@endif @if ($verified) <x-ds.badge tone="pos" icon="shield-check">Verified purchase</x-ds.badge>
@endif</p>
    <p class="ds-review__body">{{ $body }}</p>
    @if ($reply)
        <div class="ds-review__reply"><strong>Reply from {{ $reply['from'] ?? 'the seller' }}</strong><span>{{ $reply['body'] }}</span>@if (! empty($reply['date']))<span class="ds-muted">{{ $reply['date'] instanceof \DateTimeInterface ? $reply['date']->format('j M Y') : $reply['date'] }}</span>@endif</div>
    @endif
    {{ $slot }}
</article>
