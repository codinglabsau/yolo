<?php

namespace Codinglabs\Yolo;

/**
 * One reconciled attribute, so the operator sees what drifted rather than a flat SYNCED.
 * `from` is the live value (null = absent), `to` the desired. `make()` formats values so the
 * renderer stays dumb; resources comparing opaque documents build a Change directly with
 * semantic labels ('absent' → 'managed') rather than dumping a JSON blob.
 */
final readonly class Change
{
    public function __construct(
        public string $attribute,
        public ?string $from,
        public ?string $to,
    ) {}

    public static function make(string $attribute, mixed $from, mixed $to): self
    {
        return new self($attribute, self::format($from), self::format($to));
    }

    public function describe(): string
    {
        return sprintf('%s: %s → %s', $this->attribute, $this->from ?? '<absent>', $this->to ?? '<absent>');
    }

    protected static function format(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? 'true' : 'false',
            is_array($value) => json_encode($value),
            default => (string) $value,
        };
    }
}
