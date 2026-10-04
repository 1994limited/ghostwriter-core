<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo;

/**
 * One heading of a rendered page, as the preview's locator reported it
 * (locator.js `outline()`).
 */
final class OutlineHeading
{
    /**
     * @param  int  $level  1–6.
     * @param  string  $text  Its words, markers taken out.
     * @param  string|null  $field  The field it prints: a top-level handle (`title`), or a block's set and field (`hero.heading`); null for the template's own text (a logo).
     * @param  string|null  $unit  The draft unit it shows, when it is part of one.
     * @param  bool  $inContent  It sits inside a rich-text value (the body's own `##`), rather than being printed by the template from a field.
     */
    public function __construct(
        public readonly int $level,
        public readonly string $text,
        public readonly ?string $field = null,
        public readonly ?string $unit = null,
        public readonly bool $inContent = false,
    ) {}

    /**
     * @return array{level: int, text: string, field: string|null, unit: string|null, inContent: bool}
     */
    public function toArray(): array
    {
        return ['level' => $this->level, 'text' => $this->text, 'field' => $this->field, 'unit' => $this->unit, 'inContent' => $this->inContent];
    }

    /**
     * Null for anything that isn't a heading of level 1–6.
     *
     * @param  array<mixed>  $array
     */
    public static function fromArray(array $array): ?self
    {
        $level = $array['level'] ?? null;

        if (is_string($level) && preg_match('/^h?([1-6])$/i', $level, $m) === 1) {
            $level = (int) $m[1];
        }

        if (! is_int($level) || $level < 1 || $level > 6) {
            return null;
        }

        $string = fn (string $key) => is_scalar($array[$key] ?? null) && (string) $array[$key] !== '' ? (string) $array[$key] : null;

        return new self(
            $level,
            mb_substr(trim((string) ($string('text') ?? '')), 0, 200),
            $string('field'),
            $string('unit'),
            (bool) ($array['inContent'] ?? false),
        );
    }
}
