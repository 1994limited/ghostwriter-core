<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo;

/**
 * One thing HeadingFixer changed, for the log and the after-draft notice.
 */
final class HeadingChange
{
    /** A heading moved to another level. */
    public const LEVEL = 'level';

    /** An empty heading removed. */
    public const REMOVED = 'removed';

    /** A bold line posing as a heading made a heading. */
    public const FROM_BOLD = 'from-bold';

    /** A heading the editor can't show made a bold lead-in (or a bold line). */
    public const TO_LEAD_IN = 'to-lead-in';

    /**
     * @param  int|null  $from  The level it had; null for a bold line.
     * @param  int|null  $to  The level it has; null when it is no longer a heading.
     */
    public function __construct(
        public readonly string $kind,
        public readonly ?int $from,
        public readonly ?int $to,
        public readonly string $text,
    ) {}

    /**
     * @return array{kind: string, from: int|null, to: int|null, text: string}
     */
    public function toArray(): array
    {
        return ['kind' => $this->kind, 'from' => $this->from, 'to' => $this->to, 'text' => $this->text];
    }
}
