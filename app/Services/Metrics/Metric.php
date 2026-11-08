<?php

namespace App\Services\Metrics;

/**
 * One measured number with its definition. `value` is null (and `status` is "no_data") when the source has no rows
 * for the period: a missing measurement is never shown as zero. A rate also carries its numerator and denominator so
 * the reader can see how many events stand behind it.
 */
final class Metric
{
    public const OK = 'ok';

    public const NO_DATA = 'no_data';

    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $definition,
        public readonly string $unit,
        public readonly int|float|null $value,
        public readonly string $status,
        public readonly ?int $numerator = null,
        public readonly ?int $denominator = null,
        public readonly ?string $note = null,
    ) {}

    public static function rate(string $key, string $label, string $definition, int $numerator, int $denominator, ?string $note = null): self
    {
        if ($denominator <= 0) {
            return self::none($key, $label, $definition, 'rate', $note ?? 'Nothing to measure yet.');
        }

        return new self($key, $label, $definition, 'rate', round($numerator / $denominator, 4), self::OK, $numerator, $denominator, $note);
    }

    public static function count(string $key, string $label, string $definition, int $value, bool $hasSource, ?string $note = null): self
    {
        return $hasSource ? new self($key, $label, $definition, 'count', $value, self::OK, null, null, $note) : self::none($key, $label, $definition, 'count', $note ?? 'Nothing to measure yet.');
    }

    public static function money(string $key, string $label, string $definition, ?int $cents, ?string $note = null): self
    {
        return $cents === null ? self::none($key, $label, $definition, 'cents', $note ?? 'Nothing to measure yet.') : new self($key, $label, $definition, 'cents', $cents, self::OK, null, null, $note);
    }

    public static function none(string $key, string $label, string $definition, string $unit, string $note): self
    {
        return new self($key, $label, $definition, $unit, null, self::NO_DATA, null, null, $note);
    }

    public function hasData(): bool
    {
        return $this->status === self::OK;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'definition' => $this->definition,
            'unit' => $this->unit,
            'value' => $this->value,
            'status' => $this->status,
            'display' => $this->hasData() ? null : 'No data yet',
            'numerator' => $this->numerator,
            'denominator' => $this->denominator,
            'note' => $this->note,
        ];
    }
}
