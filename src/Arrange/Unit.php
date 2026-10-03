<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Arrange;

use NineteenNinetyFour\Ghostwriter\Core\Anchor\NormalisedText;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\BlockRef;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FieldPath;

/**
 * One piece of draft text with a stable id. Comments point at units, not
 * at block positions, so a comment follows its words into another layout;
 * and the id survives the writer's next turn when the words mostly do
 * (UnitMatcher).
 *
 * The id is never in the draft's YAML: no prompt or hand edit can corrupt
 * it. It lives beside the draft (Units::sidecar()).
 */
final class Unit
{
    /**
     * @param  string  $id  "u7".
     * @param  FieldPath  $path  Where the value is: by position in a draft ("page_builder/2/text"); with block IDs in an entry.
     * @param  string  $markdown  The text as written. Empty for Media.
     * @param  list<Piece>  $pieces  Its paragraphs, headings, list items… for layouts to split and join.
     * @param  string|null  $blockType  The set of the block it is in, if any.
     * @param  list<string>  $assets  Media: the stored asset references.
     * @param  int|null  $part  Which unit of its value it is, for rich text (0 for the first section); null for a value that is one unit.
     */
    public function __construct(
        public readonly string $id,
        public readonly UnitKind $kind,
        public readonly FieldPath $path,
        public readonly string $markdown,
        public readonly array $pieces = [],
        public readonly ?string $blockType = null,
        public readonly array $assets = [],
        public readonly ?int $part = null,
    ) {}

    /**
     * Its normalised text (or assets) hashed: whether it changed meanwhile.
     * Whitespace, quote and dash differences don't count.
     */
    public function hash(): string
    {
        $text = $this->kind === UnitKind::Media ? implode("\n", $this->assets) : NormalisedText::string($this->markdown);

        return substr(sha1($this->kind->value."\n".$text), 0, 16);
    }

    /** Where it is, with its part: "page_builder/2/text", "body~1". */
    public function where(): string
    {
        return $this->path->toString().($this->part === null ? '' : '~'.$this->part);
    }

    public function withId(string $id): self
    {
        return new self($id, $this->kind, $this->path, $this->markdown, $this->pieces, $this->blockType, $this->assets, $this->part);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'id' => $this->id,
            'kind' => $this->kind->value,
            'path' => $this->path->toString(),
            'part' => $this->part,
            'markdown' => $this->markdown,
            'pieces' => array_map(fn (Piece $piece) => $piece->toArray(), $this->pieces),
            'blockType' => $this->blockType,
            'assets' => $this->assets,
        ], fn (mixed $value) => $value !== null && $value !== []);
    }

    /**
     * The path as toArray() wrote it comes back without block types.
     *
     * @param  array<mixed>  $array
     */
    public static function fromArray(array $array): self
    {
        return new self(
            is_scalar($array['id'] ?? null) ? (string) $array['id'] : '',
            UnitKind::tryFrom(is_string($array['kind'] ?? null) ? $array['kind'] : '') ?? UnitKind::Prose,
            FieldPath::parse(is_string($array['path'] ?? null) ? $array['path'] : '?'),
            is_scalar($array['markdown'] ?? null) ? (string) $array['markdown'] : '',
            array_values(array_map(fn (array $piece) => Piece::fromArray($piece), array_filter(is_array($array['pieces'] ?? null) ? $array['pieces'] : [], 'is_array'))),
            is_string($array['blockType'] ?? null) ? $array['blockType'] : null,
            array_values(array_map('strval', array_filter(is_array($array['assets'] ?? null) ? $array['assets'] : [], 'is_scalar'))),
            is_int($array['part'] ?? null) ? $array['part'] : null,
        );
    }

    /** The type of the innermost block on a path, if any. */
    public static function blockTypeOf(FieldPath $path): ?string
    {
        for ($i = count($path->segments) - 1; $i >= 0; $i--) {
            $segment = $path->segments[$i];

            if ($segment instanceof BlockRef && $segment->type !== '') {
                return $segment->type;
            }
        }

        return null;
    }
}
