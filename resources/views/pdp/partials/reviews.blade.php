@php
    $sum = $reviewData['summary'];
    $write = $reviewData['write'];
    $own = $reviewData['own'];
    $page = $reviewData['page'];
    $filtered = $reviewData['verifiedOnly'] || $reviewData['withPhotos'];
    $maxStar = max(1, max($sum['stars']));
@endphp
<section class="pdp-section" id="reviews" aria-labelledby="reviews-h">
    <div class="pdp-section__head"><h2 id="reviews-h">Reviews</h2></div>

    @if ($sum['count'] > 0)
        <div class="rv-summary">
            <div class="rv-summary__score">
                <p class="rv-summary__avg" aria-hidden="true">{{ number_format($sum['average'], 1) }}</p>
                <x-ds.rating :value="$sum['average']" :count="$sum['count']" :show-score="false" :show-count="false" />
                <p class="ds-muted">{{ number_format($sum['count']) }} {{ \Illuminate\Support\Str::plural('review', $sum['count']) }}</p>
            </div>
            <ol class="rv-dist" aria-label="Reviews by star rating">
                @foreach ($sum['stars'] as $star => $n)
                    <li>
                        <span class="rv-dist__label">{{ $star }} {{ $star === 1 ? 'star' : 'stars' }}</span>
                        <span class="rv-dist__bar" aria-hidden="true"><span style="--w: {{ round($n / $maxStar * 100) }}%"></span></span>
                        <span class="rv-dist__n">{{ $n }}</span>
                    </li>
                @endforeach
            </ol>
            <div class="rv-summary__how">
                <p><strong>{{ $sum['verified'] }} of {{ $sum['count'] }}</strong> {{ $sum['verified'] === 1 ? 'is a' : 'are' }} verified {{ $sum['verified'] === 1 ? 'purchase' : 'purchases' }}@if ($sum['photos'] > 0), and {{ $sum['photos'] }} {{ $sum['photos'] === 1 ? 'has' : 'have' }} photos @endif.</p>
                <p class="ds-muted">A review says <em>Verified purchase</em> only when its writer has an order for this product that was delivered or collected on Emall. A review that looks copied, or that arrives in a burst, waits for a person to check it. Sellers can reply once; they cannot remove a review.</p>
            </div>
        </div>
    @endif

    {{-- The viewer's own review, including one that is held. --}}
    @if ($own)
        <div class="rv-own" id="your-review">
            @if ($own->status === \App\Models\Review::HELD)
                <x-ds.alert tone="cau" title="Waiting for review">Your review is not on the page yet. A person checks it first; it appears once it passes, and it does not count in the rating until then.</x-ds.alert>
            @elseif ($own->status === \App\Models\Review::REMOVED)
                <x-ds.alert tone="crit" title="Removed">The marketplace team took your review down. It is not shown and does not count in the rating.</x-ds.alert>
            @else
                <p class="ds-muted">Your review is on the page below.</p>
            @endif
            @if ($own->status !== \App\Models\Review::REMOVED)
                <div class="rv-own__actions">
                    @if ($reviewData['ownCanEdit'])
                        <details class="rpt">
                            <summary class="rpt__summary"><x-ds.icon name="edit" :size="16" />Edit my review</summary>
                            <form method="POST" action="{{ route('reviews.update', $own) }}" enctype="multipart/form-data" class="rv-form">
                                @csrf @method('PATCH')
                                <x-ds.option-picker name="rating" label="Your rating" :selected="(string) $own->rating" :options="collect([5, 4, 3, 2, 1])->map(fn ($n) => ['value' => (string) $n, 'label' => $n.($n === 1 ? ' star' : ' stars')])->all()" />
                                <x-ds.textarea name="body" id="edit-body" label="Your review" rows="4" :value="old('body', $own->body)" />
                                @if ($own->photos->count() < $reviewData['maxPhotos'])
                                    <x-ds.field label="Add photos" id="edit-photos" :hint="'Up to '.$reviewData['maxPhotos'].' photos in all, JPEG, PNG or WebP, 4 MB each.'"><input class="ds-input" type="file" id="edit-photos" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple></x-ds.field>
                                @endif
                                <p class="ds-muted">You can edit for {{ $reviewData['editDays'] }} days after posting.</p>
                                <x-ds.button type="submit" size="sm">Save changes</x-ds.button>
                            </form>
                        </details>
                    @endif
                    <x-ds.button variant="danger" size="sm" icon="trash" data-dialog-open="delete-review">Delete my review</x-ds.button>
                </div>
                <x-ds.confirm-dialog id="delete-review" title="Delete your review?" :action="route('reviews.destroy', $own)" method="DELETE" confirm-label="Delete my review">
                    <p>Your review and its photos are removed from this page, and the rating is worked out again without it. This cannot be undone.</p>
                </x-ds.confirm-dialog>
            @endif
        </div>
    @endif

    {{-- Write a review, or why not. --}}
    @if ($write['state'] === 'eligible')
        <form method="POST" action="{{ route('reviews.store', $product) }}" enctype="multipart/form-data" class="rv-form ds-panel" id="write-review">
            @csrf
            <h3>Write a review</h3>
            <p class="ds-muted">Your order was delivered, so your review will say <em>Verified purchase</em>.</p>
            <x-ds.option-picker name="rating" label="Your rating" :selected="old('rating')" :options="collect([5, 4, 3, 2, 1])->map(fn ($n) => ['value' => (string) $n, 'label' => $n.($n === 1 ? ' star' : ' stars')])->all()" />
            @error('rating')<p class="ds-field__error"><x-ds.icon name="alert-triangle" :size="16" /><span>{{ $message }}</span></p>@enderror
            <x-ds.textarea name="body" id="review-body" label="Your review (optional)" rows="4" hint="What was it like to use? Up to 2000 characters." />
            <x-ds.field label="Photos (optional)" id="review-photos" :hint="'Up to '.$reviewData['maxPhotos'].' photos, JPEG, PNG or WebP, 4 MB each. We strip location data.'" :error="$errors->first('photos') ?: $errors->first('photos.*')">
                <input class="ds-input" type="file" id="review-photos" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple>
            </x-ds.field>
            <div><x-ds.button type="submit">Post review</x-ds.button></div>
        </form>
    @elseif ($write['message'])
        <p class="rv-why"><x-ds.icon name="info" :size="18" /><span>{{ $write['message'] }}@if ($write['state'] === 'guest') <a href="{{ route('login') }}">Sign in</a>@endif</span></p>
    @endif

    @if ($sum['count'] > 0 || $filtered)
        <form method="GET" action="{{ route('products.show', $product) }}#reviews" class="rv-toolbar" data-autosubmit-form>
            @foreach (request()->query() as $k => $v)
                @if (! str_starts_with($k, 'reviews_') && is_string($v))<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif
            @endforeach
            <div class="ds-field rv-toolbar__sort">
                <label class="ds-field__label" for="reviews_sort">Sort reviews</label>
                <div class="ds-select-wrap"><select class="ds-select" id="reviews_sort" name="reviews_sort" data-autosubmit>
                    @foreach ($reviewData['sorts'] as $value => $text)<option value="{{ $value }}" @selected($reviewData['sort'] === $value)>{{ $text }}</option>@endforeach
                </select></div>
            </div>
            <div class="rv-toolbar__filters">
                <x-ds.checkbox name="reviews_verified" id="f-verified" label="Verified purchases only" :checked="$reviewData['verifiedOnly']" data-autosubmit />
                <x-ds.checkbox name="reviews_photos" id="f-photos" label="With photos" :checked="$reviewData['withPhotos']" data-autosubmit />
            </div>
            <noscript><x-ds.button type="submit" variant="quiet" size="sm">Apply</x-ds.button></noscript>
        </form>
    @endif

    @if ($page->isEmpty())
        @if ($filtered)
            <x-ds.empty-state title="No reviews match" icon="message">Try removing a filter.<x-slot:actions><x-ds.button :href="route('products.show', $product).'#reviews'" variant="quiet" size="sm">Show all reviews</x-ds.button></x-slot:actions></x-ds.empty-state>
        @elseif ($sum['count'] === 0)
            <p class="ds-muted rv-none">No reviews yet. Reviews appear here once buyers whose orders were delivered write them.</p>
        @endif
    @else
        <div class="rv-list">
            @foreach ($page as $review)
                <x-ds.review-card :id="'review-'.$review->id" :author="$review->user?->publicName() ?? 'Buyer'" :rating="$review->rating" :body="$review->body ?: 'No written comment.'" :date="$review->created_at" :verified="(bool) $review->verified_purchase"
                    :reply="$review->response ? ['from' => $product->store->name, 'body' => $review->response->body, 'date' => $review->response->created_at] : null">
                    @if ($review->edited_at)<p class="ds-muted rv-edited">Edited {{ $review->edited_at->format('j M Y') }}</p>@endif
                    @if ($review->photos->isNotEmpty())
                        <ul class="rv-photos" aria-label="Photos from this review">
                            @foreach ($review->photos as $i => $photo)
                                <li><a href="{{ route('review-photos.show', $photo) }}" data-lightbox="review-{{ $review->id }}" data-alt="Photo {{ $i + 1 }} of {{ $review->photos->count() }} from {{ $review->user?->publicName() ?? 'a buyer' }}'s review"><img src="{{ route('review-photos.show', $photo) }}" alt="Photo {{ $i + 1 }} from {{ $review->user?->publicName() ?? 'a buyer' }}'s review" width="96" height="96" loading="lazy" decoding="async"></a></li>
                            @endforeach
                        </ul>
                    @endif
                    <div class="rv-actions">
                        @if ($user && $user->id !== $review->user_id)
                            <form method="POST" action="{{ route('reviews.vote', $review) }}" data-vote>
                                @csrf
                                @php($did = in_array($review->id, $reviewData['voted'], true))
                                <button type="submit" class="ds-btn ds-btn--quiet ds-btn--sm" aria-pressed="{{ $did ? 'true' : 'false' }}" data-vote-button><x-ds.icon name="check" :size="16" /><span>Helpful</span> <span class="rv-count" data-vote-count>({{ $review->helpful_count }})</span></button>
                            </form>
                            <x-report-form :action="route('reviews.report', $review)" :bag="'reportreview'.$review->id" what="this review" />
                        @elseif (! $user)
                            <a class="ds-btn ds-btn--quiet ds-btn--sm" href="{{ route('login') }}" title="Accounts come from InfoDot. Sign in to vote.">Helpful ({{ $review->helpful_count }})</a>
                        @elseif ($review->helpful_count > 0)
                            <span class="ds-muted">{{ $review->helpful_count }} found this helpful</span>
                        @endif
                    </div>
                    @if ($reviewData['canSpeakForStore'] && ! $review->response)
                        <details class="rpt">
                            <summary class="rpt__summary"><x-ds.icon name="message" :size="16" />Reply as {{ $product->store->name }}</summary>
                            <form method="POST" action="{{ route('reviews.reply', $review) }}" class="rv-form">
                                @csrf
                                <x-ds.textarea name="body" :id="'reply-'.$review->id" label="Your reply" rows="3" hint="One public reply per review. You cannot edit or delete it afterwards." :error="$errors->getBag('reply'.$review->id)->first('body')" />
                                <x-ds.button type="submit" size="sm">Post reply</x-ds.button>
                            </form>
                        </details>
                    @endif
                </x-ds.review-card>
            @endforeach
        </div>
        <x-ds.pagination :paginator="$page" />
    @endif
</section>
