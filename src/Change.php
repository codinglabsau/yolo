<?php

namespace Codinglabs\Yolo;

/**
 * A single reconciled attribute — the unit of detail a sync step reports so the
 * operator sees exactly what drifted (and what it became) rather than a flat
 * SYNCED. `from` is the live value (null = absent / never set), `to` the desired
 * value the sync would apply or did apply.
 *
 * Values arrive as scalars, bools or whole config documents; `make()` formats
 * them into the display strings the renderer prints, so the renderer stays dumb.
 * Resources comparing opaque documents (a bucket policy, an event pattern) build
 * a Change directly with their own semantic from/to labels (e.g. 'absent' →
 * 'managed') rather than dumping a JSON blob.
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

    /**
     * Render the comparison as a single line for non-coloured output.
     */
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
