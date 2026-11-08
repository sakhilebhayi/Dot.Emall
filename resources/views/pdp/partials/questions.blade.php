@php
    $askErrors = $errors->getBag('ask');
    $canAnswer = $user && app(\App\Services\Community\StoreMembers::class)->canSpeakFor($user, $product->store);
@endphp
<section class="pdp-section" id="questions" aria-labelledby="questions-h">
    <div class="pdp-section__head"><h2 id="questions-h">Questions and answers</h2></div>
    <p class="ds-muted qa-lead">Questions are answered by {{ $product->store->name }}, the store that sells this. Their answers are marked as coming from the store.</p>

    @if (! $isLive)
        {{-- Nobody asks about a listing that is not on sale. --}}
    @elseif (! $user)
        <p class="rv-why"><x-ds.icon name="info" :size="18" /><span><a href="{{ route('login') }}">Sign in with InfoDot</a> to ask the store a question. Accounts come from InfoDot.</span></p>
    @elseif ($canAnswer)
        <p class="rv-why"><x-ds.icon name="info" :size="18" /><span>You sell this product. Answer the questions below instead of asking.</span></p>
    @else
        <form method="POST" action="{{ route('questions.store', $product) }}" class="qa-ask" id="ask">
            @csrf
            <x-ds.field label="Ask the store" id="question-body" hint="10 to 500 characters. One question about this product per day. Do not put contact details in it." :error="$askErrors->first('body')">
                <textarea class="ds-textarea" id="question-body" name="body" rows="3" maxlength="500" required @if ($askErrors->has('body')) aria-invalid="true" aria-describedby="question-body-hint question-body-error" @else aria-describedby="question-body-hint" @endif>{{ old('body') }}</textarea>
            </x-ds.field>
            <div><x-ds.button type="submit" icon="message">Ask the store</x-ds.button></div>
        </form>
    @endif

    @if ($questions->isEmpty())
        <p class="ds-muted rv-none">No questions yet.</p>
    @else
        <ul class="qa-list">
            @foreach ($questions as $q)
                @php($held = $q->status === \App\Models\ProductQuestion::HELD)
                <li class="qa" id="question-{{ $q->id }}">
                    <div class="qa__q">
                        <p class="qa__who"><strong>Question</strong> from {{ $q->user?->publicName() ?? 'a shopper' }}, {{ $q->created_at->format('j M Y') }}</p>
                        <p class="qa__text">{{ $q->body }}</p>
                        @if ($held)<x-ds.badge tone="cau" icon="hourglass">Waiting for review. Only you can see this.</x-ds.badge>@endif
                    </div>
                    @foreach ($q->answers as $a)
                        <div class="qa__a" id="answer-{{ $a->id }}">
                            <p class="qa__who"><strong>Answer</strong> @if ($a->is_seller)<x-ds.badge tone="info" icon="store">From {{ $product->store->name }}</x-ds.badge>@endif <span class="ds-muted">{{ $a->created_at->format('j M Y') }}</span></p>
                            <p class="qa__text">{{ $a->body }}</p>
                            @if ($user && $user->id !== $a->user_id)<x-report-form :action="route('answers.report', $a)" :bag="'reportanswer'.$a->id" what="this answer" />@endif
                        </div>
                    @endforeach
                    @if ($q->answers->isEmpty() && ! $held)
                        <p class="ds-muted qa__none">Not answered yet.</p>
                    @endif
                    <div class="qa__actions">
                        @if ($canAnswer && ! $held)
                            <details class="rpt">
                                <summary class="rpt__summary"><x-ds.icon name="message" :size="16" />{{ $q->answers->isEmpty() ? 'Answer as '.$product->store->name : 'Add an answer' }}</summary>
                                <form method="POST" action="{{ route('questions.answer', $q) }}" class="rv-form">
                                    @csrf
                                    <x-ds.textarea name="body" :id="'answer-body-'.$q->id" label="Your answer" rows="3" :error="$errors->getBag('answer'.$q->id)->first('body')" />
                                    <x-ds.button type="submit" size="sm">Post answer</x-ds.button>
                                </form>
                            </details>
                        @endif
                        @if ($user && $user->id === $q->user_id && $q->answers->isEmpty())
                            <form method="POST" action="{{ route('questions.destroy', $q) }}">@csrf @method('DELETE')<x-ds.button type="submit" variant="link" size="sm">Withdraw my question</x-ds.button></form>
                        @elseif ($user && $user->id !== $q->user_id && ! $held)
                            <x-report-form :action="route('questions.report', $q)" :bag="'reportquestion'.$q->id" what="this question" />
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</section>
