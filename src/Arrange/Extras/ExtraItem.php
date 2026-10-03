<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\Markers;

/**
 * One item of an extra: a stat, a question and its answer, a quote. It has
 * a source for every fact in it, or no source and an `[[ask: …]]` in place
 * of the fact it needs ("needs your answer"). An item with neither is never
 * kept (ExtrasReader).
 *
 * Its id is "x<extra>.<item>": "x2.1". Layouts place it by that id, or one
 * of its parts by "x2.1.question".
 */
final class ExtraItem
{
    /**
     * @param  array<string, string>  $parts  Named parts besides the text (ExtraKind::parts()): `question`, `attribution`…
     * @param  list<string>  $askHints  The hints of the `[[ask: …]]` markers in it.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $text,
        public readonly array $parts = [],
        public readonly ?Source $source = null,
        public readonly array $askHints = [],
    ) {}

    /** Whether it still needs a fact from the editor: no source, and an ask marker in its place. */
    public function needsAnswer(): bool
    {
        return $this->source === null && $this->askHints !== [];
    }

    /** The text of the item or one of its parts; null for a part it doesn't have. */
    public function part(?string $name): ?string
    {
        if ($name === null || $name === 'text') {
            return $this->text;
        }

        return $this->parts[$name] ?? null;
    }

    /** Everything it says, for word counts and fact checks. */
    public function words(): string
    {
        return trim(implode("\n", [$this->parts['question'] ?? '', $this->text]));
    }

    public function withId(string $id): self
    {
        return new self($id, $this->text, $this->parts, $this->source, $this->askHints);
    }

    /**
     * The same item as the editor changed it: their words are the source.
     *
     * @param  array<string, string>|null  $parts  Null keeps the parts as they are.
     */
    public function editedTo(string $text, ?array $parts = null): self
    {
        $parts ??= $this->parts;
        $hints = array_column(Markers::asks($text."\n".implode("\n", $parts)), 'hint');

        return new self($this->id, trim($text), $parts, new Source(SourceKind::Editor, trim($text)), array_values($hints));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'text' => $this->text]
            + ($this->parts !== [] ? ['parts' => $this->parts] : [])
            + ($this->source !== null ? ['source' => $this->source->toArray()] : [])
            + ($this->askHints !== [] ? ['askHints' => $this->askHints] : []);
    }

    /**
     * @param  array<mixed>  $array
     */
    public static function fromArray(array $array): self
    {
        $parts = [];

        foreach (is_array($array['parts'] ?? null) ? $array['parts'] : [] as $name => $value) {
            if (is_string($name) && is_scalar($value)) {
                $parts[$name] = (string) $value;
            }
        }

        return new self(
            is_scalar($array['id'] ?? null) ? (string) $array['id'] : '',
            is_scalar($array['text'] ?? null) ? (string) $array['text'] : '',
            $parts,
            is_array($array['source'] ?? null) ? Source::fromArray($array['source']) : null,
            array_values(array_map('strval', array_filter(is_array($array['askHints'] ?? null) ? $array['askHints'] : [], 'is_scalar'))),
        );
    }
}
