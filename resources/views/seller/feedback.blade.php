@php
    $tabs = ['questions' => ['Questions', $unanswered], 'reviews' => ['Reviews', $unreplied], 'reports' => ['Reports', 0]];
    $reasons = ['spam' => 'Spam', 'fake' => 'Looks fake', 'offensive' => 'Offensive', 'off_topic' => 'Off topic', 'personal_info' => 'Personal information', 'other' => 'Something else'];
@endphp
<x-seller-layout>
    <x-slot:title>Reviews and questions</x-slot:title>
    <x-slot:heading>Reviews and questions</x-slot:heading>
    <x-slot:lead>Answer shoppers and reply to reviews. You cannot hide or delete either: only the Emall team can.</x-slot:lead>

    <div class="sd-page">
        <x-seller.errors />
        <nav class="sd-tabnav" aria-label="Feedback">
            @foreach ($tabs as $key => [$label, $n])
                <a href="{{ route('seller.feedback', ['tab' => $key]) }}" @if ($tab === $key) aria-current="page" @endif>{{ $label }}@if ($n)<span class="ds-count">{{ $n }}</span>@endif</a>
            @endforeach
        </nav>

        @if ($tab === 'questions')
            @if ($questions->isEmpty())
                <x-ds.empty-state title="No questions yet" icon="message">When a shopper asks about one of your products, it appears here and you are told.</x-ds.empty-state>
            @else
                <ul class="sd-list">
                    @foreach ($questions as $q)
                        <li class="sd-item sd-item--nobox">
                            <div class="sd-item__main">
                                <p><strong>{{ $q->body }}</strong></p>
                                <div class="sd-item__meta"><span>About {{ $q->product?->name ?? 'a product' }}</span><span>{{ $q->user?->name ? \Illuminate\Support\Str::of($q->user->name)->before(' ') : 'A shopper' }}, {{ $q->created_at->format('j M Y') }}</span></div>
                                @foreach ($q->answers as $a)<div class="sd-do"><p>{{ $a->body }}</p><p class="sd-quiet">Your answer, {{ $a->created_at->format('j M Y') }}</p></div>@endforeach
                                @if ($q->answers_count === 0)
                                    <form method="POST" action="{{ route('seller.questions.answer', $q) }}" class="sd-stack sd-stack--tight" novalidate>@csrf
                                        <x-ds.textarea :label="'Your answer to: '.\Illuminate\Support\Str::limit($q->body, 60)" name="body" :id="'answer-'.$q->id" rows="3" maxlength="1500" :hint="'Everyone can read it. Up to 1,500 characters.'" />
                                        <div><x-ds.button type="submit" variant="primary" size="sm">Post answer</x-ds.button></div>
                                    </form>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        @elseif ($tab === 'reviews')
            @if ($reviews->isEmpty())
                <x-ds.empty-state title="No reviews yet" icon="star">Buyers can review a product after it has been delivered.</x-ds.empty-state>
            @else
                <ul class="sd-list">
                    @foreach ($reviews as $r)
                        <li class="sd-item sd-item--nobox">
                            <div class="sd-item__main">
                                <div class="sd-cluster"><x-ds.rating :value="(float) $r->rating" :count="1" :showCount="false" /><span class="sd-quiet">{{ $r->rating }} out of 5 for {{ $r->product?->name ?? 'a product' }}</span>@if ($r->verified_purchase)<x-ds.badge tone="pos" icon="check-circle">Bought on Emall</x-ds.badge>@endif</div>
                                <p>{{ $r->body ?: 'No written review.' }}</p>
                                <div class="sd-item__meta"><span>{{ $r->user?->name ? \Illuminate\Support\Str::of($r->user->name)->before(' ') : 'A shopper' }}, {{ $r->created_at->format('j M Y') }}</span></div>
                                @if ($r->response)<div class="sd-do"><p>{{ $r->response->body }}</p><p class="sd-quiet">Your reply, {{ $r->response->created_at->format('j M Y') }}. It cannot be edited.</p></div>
                                @else
                                    <form method="POST" action="{{ route('seller.reviews.reply', $r) }}" class="sd-stack sd-stack--tight" novalidate>@csrf
                                        <x-ds.textarea :label="'Reply to this '.$r->rating.' star review'" name="body" :id="'reply-'.$r->id" rows="3" maxlength="1000" hint="One reply per review. It is public, and you cannot edit or delete it afterwards, so be polite and brief." />
                                        <div><x-ds.button type="submit" variant="primary" size="sm">Post reply</x-ds.button></div>
                                    </form>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        @else
            <p class="sd-muted sd-measure">Shoppers can report a review or a question. A report never hides anything by itself: the Emall team looks at it and decides. You see here that a report exists and why it was made, not who made it.</p>
            @if ($reports->isEmpty())
                <x-ds.empty-state title="No reports" icon="flag">Nobody has reported a review or a question on your products.</x-ds.empty-state>
            @else
                <x-ds.table caption="Reports against reviews and questions on your products" :stack="true">
                    <x-slot:head><tr><th scope="col">Reported</th><th scope="col">What</th><th scope="col">Reason</th><th scope="col">Status</th></tr></x-slot:head>
                    @foreach ($reports as $rp)
                        <tr><td data-label="Reported">{{ $rp->created_at->format('j M Y') }}</td><td data-label="What">{{ ucfirst($rp->reportable_type) }}</td><td data-label="Reason">{{ $reasons[$rp->reason] ?? $rp->reason }}</td><td data-label="Status">{{ ['open' => 'With the Emall team', 'actioned' => 'Acted on', 'dismissed' => 'Dismissed'][$rp->status] ?? $rp->status }}</td></tr>
                    @endforeach
                </x-ds.table>
            @endif
        @endif
    </div>
</x-seller-layout>
