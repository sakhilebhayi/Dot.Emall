<x-operator-layout>
    <x-slot:title>Reviews and questions</x-slot:title>
    <x-slot:heading>Reviews and questions</x-slot:heading>
    <x-slot:lead>Held for a check before they appear. Release puts one on the product page; removing needs a reason, which the author reads. Things people reported are under Reports.</x-slot:lead>

    <x-op.chips label="What is held" :items="[
        ['label' => 'Reviews held', 'href' => route('operator.moderation.index', ['kind' => 'reviews']), 'current' => $kind === 'reviews', 'count' => $heldReviews],
        ['label' => 'Questions held', 'href' => route('operator.moderation.index', ['kind' => 'questions']), 'current' => $kind === 'questions', 'count' => $heldQuestions],
    ]" />
    @if ($reportedReviews + $reportedQuestions > 0)
        <p class="op-muted"><a href="{{ route('operator.reports', ['type' => $kind === 'reviews' ? 'review' : 'question']) }}">{{ $kind === 'reviews' ? $reportedReviews : $reportedQuestions }} reported {{ $kind }} are in the reports queue.</a></p>
    @endif

    @if ($items->isEmpty())
        <x-ds.empty-state title="No {{ $kind === 'reviews' ? 'review' : 'question' }} is held" icon="check-circle">
            {{ $kind === 'reviews' ? 'A review is held when someone posts more than three in a day, or when its text looks copied or contains contact details.' : 'A question is held when it contains a link, an e-mail address or a phone number.' }}
        </x-ds.empty-state>
    @else
        <ul class="op-items" aria-label="Held {{ $kind }}">
            @foreach ($items as $item)
                @php($bag = ($kind === 'reviews' ? 'review-' : 'question-').$item->id)
                <li class="op-item">
                    <div class="op-item__head">
                        <strong>{{ $item->user?->name ?? 'A former user' }}</strong>
                        <span class="op-item__meta">
                            @if ($kind === 'reviews')<x-ds.rating :value="$item->rating" />@endif
                            <span>on {{ $item->product?->name ?? 'a removed product' }}</span>
                        </span>
                    </div>
                    <div class="op-item__meta">
                        <span>Posted <x-op.age :at="$item->created_at" /> ago</span>
                        @if ($kind === 'reviews')<span>{{ $item->verified_purchase ? 'Verified purchase' : 'Not a verified purchase' }}</span>@endif
                        @if ($item->user)<span>Account {{ $item->user->created_at ? 'opened ' : '' }}<x-op.age :at="$item->user->created_at" /> {{ $item->user->created_at ? 'ago' : '' }}</span>@endif
                        @if ($kind === 'reviews' && $item->photos->count())<span>{{ $item->photos->count() }} {{ \Illuminate\Support\Str::plural('photo', $item->photos->count()) }}</span>@endif
                    </div>
                    @if ($item->held_reason)<x-ds.alert tone="cau">Held because: {{ str_replace('_', ' ', $item->held_reason) }}</x-ds.alert>@endif
                    <p class="op-quote">{{ $item->body ?: 'No written review, rating only.' }}</p>
                    <div class="op-decisions">
                        <form method="POST" action="{{ route($kind === 'reviews' ? 'operator.moderation.reviews.release' : 'operator.moderation.questions.release', $item) }}">@csrf<x-ds.button type="submit" size="sm">Release</x-ds.button></form>
                        <x-op.decide :bag="$bag" :action="route($kind === 'reviews' ? 'operator.moderation.reviews.remove' : 'operator.moderation.questions.remove', $item)" summary="Remove" submit="Remove and tell the author" tone="danger" label="Why it is removed" hint="The author is told this reason." />
                    </div>
                </li>
            @endforeach
        </ul>
        <x-ds.pagination :paginator="$items" />
    @endif
</x-operator-layout>
