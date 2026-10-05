<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Studio;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Image;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Gap;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapKind;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapRefused;

/**
 * One "fix that writes", for Studio::fillGap(), made only when an editor
 * presses a button that says it uses Ghostwriter:
 *
 * - `summary`: a one-line blurb from the page's own text;
 * - `shorten`: the given text within a limit;
 * - `shorten-heading`: a heading over 70 characters (Gaps\Detectors\LongHeadings),
 *   shorter, with the same meaning and nothing added;
 * - `write-around`: a sentence holding `[[ask: …]]` rewritten without the
 *   fact, adding nothing;
 * - `alt`: alt text for an image (vision), refused for images the
 *   model-input guard won't let a model see.
 *
 * **It never fills a fact.** A request can't be made for a fact meant for
 * a non-text field (AskValue) at all, and for a fact in text (Ask) only to
 * write around it: GapRefused is thrown before any model is asked.
 */
final class GapRequest
{
    public const SUMMARY = 'summary';

    public const SHORTEN = 'shorten';

    public const WRITE_AROUND = 'write-around';

    public const SHORTEN_HEADING = 'shorten-heading';

    public const ALT = 'alt';

    /** The usual length of a summary line, in characters. */
    public const SUMMARY_LIMIT = 160;

    /** The longest alt text asked for. */
    public const ALT_LIMIT = 125;

    private function __construct(
        public readonly string $task,
        public readonly string $label,
        public readonly string $text,
        public readonly ?int $limit = null,
        public readonly ?string $missing = null,
        public readonly ?Image $image = null,
        public readonly ?AssetRef $asset = null,
        public readonly ?string $filename = null,
        public readonly ?Gap $gap = null,
        public readonly ?string $around = null,
    ) {}

    /**
     * A one-line blurb for a field ("Summary") from the page's own text.
     *
     * @throws GapRefused for a fact to add.
     */
    public static function summary(string $label, string $pageText, int $limit = self::SUMMARY_LIMIT, ?Gap $gap = null): self
    {
        self::refuseFacts($gap);

        return new self(self::SUMMARY, $label, $pageText, max(20, $limit), gap: $gap);
    }

    /**
     * The text, within $limit characters.
     *
     * @throws GapRefused for a fact to add.
     */
    public static function shorten(string $label, string $text, int $limit, ?Gap $gap = null): self
    {
        self::refuseFacts($gap);

        return new self(self::SHORTEN, $label, $text, max(20, $limit), gap: $gap);
    }

    /**
     * A long heading (a `heading-long` gap), shorter: within the gap's
     * target (60 characters), the same meaning, in the page's words. The
     * text around it is given for context only.
     *
     * @throws GapRefused unless the gap is a long heading.
     */
    public static function shortenHeading(Gap $gap, string $context = ''): self
    {
        if ($gap->kind !== GapKind::HeadingLong || trim((string) $gap->hint) === '') {
            throw GapRefused::fact();
        }

        $target = is_int($gap->meta['target'] ?? null) ? $gap->meta['target'] : 60;

        return new self(self::SHORTEN_HEADING, $gap->label, trim((string) $gap->hint), $target, gap: $gap, around: trim($context) !== '' ? trim(mb_substr($context, 0, 1500)) : null);
    }

    /**
     * The sentence holding the gap's `[[ask: …]]`, rewritten without the
     * fact (the gap's excerpt, or the sentence the front end has).
     *
     * @throws GapRefused unless the gap is a fact to add in text.
     */
    public static function writeAround(Gap $gap, ?string $sentence = null): self
    {
        if ($gap->kind !== GapKind::Ask) {
            throw GapRefused::fact();
        }

        return new self(self::WRITE_AROUND, $gap->label, $sentence ?? (string) $gap->excerpt, missing: (string) $gap->hint, gap: $gap);
    }

    /**
     * Alt text for an image, with the page's text for context. The asset
     * or file name lets the model-input guard refuse Getty and iStock
     * images by their ledger record or name, not only their bytes.
     *
     * @throws GapRefused for a fact to add.
     */
    public static function alt(string $label, Image $image, string $pageText = '', ?AssetRef $asset = null, ?string $filename = null, ?Gap $gap = null): self
    {
        self::refuseFacts($gap);

        return new self(self::ALT, $label, $pageText, self::ALT_LIMIT, image: $image, asset: $asset, filename: $filename ?? $asset?->filename(), gap: $gap);
    }

    /**
     * What the model is sent, apart from the image.
     */
    public function prompt(): string
    {
        $lines = ["Task: {$this->task}", "Field: {$this->label}"];

        if ($this->limit !== null) {
            $lines[] = "Limit: {$this->limit} characters";
        }

        if ($this->missing !== null) {
            $lines[] = "Missing: {$this->missing}";
        }

        $name = match ($this->task) {
            self::WRITE_AROUND => 'sentence',
            self::SHORTEN => 'text',
            self::SHORTEN_HEADING => 'heading',
            default => 'page',
        };

        $around = $this->around !== null ? "\n\n<around>\n{$this->around}\n</around>" : '';

        return implode("\n", $lines)."\n\n<{$name}>\n".trim($this->text)."\n</{$name}>".$around;
    }

    private static function refuseFacts(?Gap $gap): void
    {
        if ($gap !== null && in_array($gap->kind, [GapKind::Ask, GapKind::AskValue], true)) {
            throw GapRefused::fact();
        }
    }
}
