<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\ProductQuestion;
use App\Models\User;
use App\Services\Community\StoreMembers;

class ProductQuestionPolicy
{
    public function __construct(private readonly StoreMembers $members) {}

    /** Anyone signed in, except the people who sell the product. */
    public function create(User $user, Product $product): bool
    {
        return ! $this->members->canSpeakFor($user, $product->store);
    }

    /** Only the store's own people answer. */
    public function answer(User $user, ProductQuestion $question): bool
    {
        $question->loadMissing('product.store');

        return $question->status === ProductQuestion::PUBLISHED && $this->members->canSpeakFor($user, $question->product->store);
    }

    /** The asker may withdraw an unanswered question. */
    public function delete(User $user, ProductQuestion $question): bool
    {
        return $user->id === $question->user_id && (int) $question->answers_count === 0;
    }

    /** Hiding, releasing a held question: the operator. */
    public function moderate(User $user, ProductQuestion $question): bool
    {
        return $this->members->isOperator($user);
    }

    public function report(User $user, ProductQuestion $question): bool
    {
        return $user->id !== $question->user_id && $question->status === ProductQuestion::PUBLISHED;
    }
}
