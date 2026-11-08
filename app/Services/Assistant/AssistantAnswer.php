<?php

namespace App\Services\Assistant;

/**
 * What the shopping assistant returns. The two halves are kept apart on purpose:
 *
 *   catalogue   facts read from the catalogue (price, stock, rating, delivery, attributes): true by construction
 *   suggestion  our reading of those facts (a headline, a reason per pick, ways to narrow): an opinion, labelled so
 *
 * `mode` says who wrote the suggestion text: "rules" (deterministic, from the facts) or "model" (an AI model
 * rewrote it, and every claim in it was checked against the facts before it was accepted).
 */
final class AssistantAnswer
{
    /**
     * @param  list<array{kind: string, text: string}>  $understood
     * @param  list<array{key: string, label: string, value: string, inferred: bool}>  $filters
     * @param  list<array{product_id: int, name: string, store: string, image_id: int|null, facts: list<array{label: string, value: string}>}>  $catalogue
     * @param  array{headline: string, picks: list<array{product_id: int, reason: string, source: string}>, refinements: list<array{label: string, params: array<string, mixed>}>}  $suggestion
     * @param  list<string>  $notes
     */
    public function __construct(
        public readonly string $mode,
        public readonly string $request,
        public readonly array $understood,
        public readonly array $filters,
        public readonly int $total,
        public readonly array $catalogue,
        public readonly array $suggestion,
        public readonly array $notes = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'request' => $this->request,
            'understood' => $this->understood,
            'filters' => $this->filters,
            'total' => $this->total,
            'catalogue' => ['label' => 'From the catalogue', 'items' => $this->catalogue],
            'suggestion' => ['label' => 'Our suggestion'] + $this->suggestion,
            'notes' => $this->notes,
        ];
    }
}
