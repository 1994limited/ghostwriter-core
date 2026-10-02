<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\RoundTrips;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Studio\ContentKind;

/**
 * One kind of content written into a collection, section or resource: the
 * questions asked before writing, and guidance on what a good one looks
 * like (Statamic's and Craft's content type, Filament's kind). The fields
 * themselves are read from the CMS each time, not kept here.
 *
 * Every group also has a general brief, "Something new" (generic()),
 * which needs no teaching; its handle is `any:` and the group's handle.
 *
 * Kept as Statamic's YAML files (`resources/ghostwriter/types/<handle>.yaml`,
 * the handle being the file name), Craft's `type` documents (YAML) and
 * Filament's `ghostwriter_kinds` rows (the `definition` column as JSON).
 * For Statamic and Craft, fromArray() takes the decoded YAML and the
 * handle; for Filament, the row (with `handle` in it).
 */
final class ContentType
{
    use RoundTrips;

    public const GENERIC = 'any:';

    /**
     * @param  array<int, array<string, mixed>>  $questions  Each with `handle` and `label`, and maybe `type`, `instructions`, `required`, `options`.
     * @param  array<int, string>  $checklist
     * @param  string|null  $variant  The blueprint (Statamic) or entry type (Craft) written; null for the group's first.
     * @param  array<string, mixed>  $where  Narrows which existing records it learns from.
     * @param  array<string, mixed>  $defaults  Values set on everything written as this kind.
     * @param  array<int, int|string>  $examples  Records to learn from, instead of the group's newest.
     */
    public function __construct(
        public readonly Format $format,
        public readonly string $handle,
        public readonly string $title,
        public readonly string $description,
        public readonly string $group,
        public readonly array $questions,
        public readonly string $guidance,
        public readonly array $checklist = [],
        public readonly ?string $variant = null,
        public readonly array $where = [],
        public readonly array $defaults = [],
        public readonly array $examples = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data  The decoded YAML (Statamic, Craft) or the row (Filament).
     * @param  string|null  $handle  Where the record doesn't hold it: Statamic's file name, Craft's document handle.
     */
    public static function fromArray(array $data, Format $format, ?string $handle = null): self
    {
        $definition = $data;

        if ($format === Format::Filament) {
            $handle ??= is_scalar($data['handle'] ?? null) ? (string) $data['handle'] : '';
            $decoded = is_string($data['definition'] ?? null) ? json_decode($data['definition'], true) : ($data['definition'] ?? []);
            $definition = ['resource' => $data['resource'] ?? ''] + (is_array($decoded) ? $decoded : []);
        }

        $handle ??= '';
        $questions = [];

        foreach ((array) ($definition['questions'] ?? []) as $question) {
            if (is_array($question) && isset($question['handle'], $question['label'])) {
                $questions[] = $question;
            }
        }

        $variantKey = $format === Format::Craft ? 'entryType' : 'blueprint';
        $examples = (array) ($definition['examples'] ?? []);

        $type = new self(
            $format,
            $handle,
            is_scalar($definition['title'] ?? null) ? (string) $definition['title'] : ucfirst(str_replace(['-', '_'], ' ', $handle)),
            trim(is_scalar($definition['description'] ?? null) ? (string) $definition['description'] : ''),
            is_scalar($definition[$format->groupKey()] ?? null) ? (string) $definition[$format->groupKey()] : '',
            $questions,
            trim(is_scalar($definition['guidance'] ?? null) ? (string) $definition['guidance'] : ''),
            array_values(array_filter((array) ($definition['checklist'] ?? []), 'is_string')),
            $format === Format::Filament ? null : (is_string($definition[$variantKey] ?? null) && $definition[$variantKey] !== '' ? $definition[$variantKey] : null),
            $format === Format::Filament ? [] : (array) ($definition['where'] ?? []),
            $format === Format::Filament ? [] : (array) ($definition['defaults'] ?? []),
            match ($format) {
                Format::Statamic => array_values(array_filter($examples, 'is_string')),
                Format::Craft => array_values(array_map('intval', array_filter($examples, 'is_numeric'))),
                Format::Filament => array_values(array_filter($examples, fn ($key) => is_int($key) || (is_string($key) && $key !== ''))),
            },
        );

        return $type->remember($data, $format);
    }

    /**
     * The stored shape: the YAML's data (Statamic, Craft), or the row
     * (Filament) with `definition` as JSON.
     *
     * @return array<string, mixed>
     */
    public function toArray(?Format $format = null): array
    {
        return $this->emit($format ?? $this->format);
    }

    /**
     * The definition alone, as all three keep it: what Statamic and Craft
     * write as YAML and Filament as its `definition` column.
     *
     * @return array<string, mixed>
     */
    public function definition(?Format $format = null): array
    {
        $format ??= $this->format;

        return array_filter([
            'title' => $this->title,
            'description' => $this->description,
            $format->groupKey() => $this->group,
            ...($format === Format::Filament ? [] : [
                ($format === Format::Craft ? 'entryType' : 'blueprint') => $this->variant,
                'where' => $this->where ?: null,
                'defaults' => $this->defaults ?: null,
            ]),
            'examples' => $this->examples ?: null,
            'questions' => $this->questions,
            'guidance' => $this->guidance,
            'checklist' => $this->checklist,
        ], fn ($value) => $value !== null);
    }

    /**
     * The general brief every group has: "Something new".
     *
     * @param  string  $label  The group's name, as the CMS shows it.
     */
    public static function generic(Format $format, string $group, string $label): self
    {
        $records = $format === Format::Filament ? 'records' : 'entries';
        $place = match ($format) {
            Format::Statamic => 'collection',
            Format::Craft => 'section',
            Format::Filament => 'resource',
        };

        return new self(
            $format,
            self::GENERIC.$group,
            'Something new',
            "A general brief for anything in {$label}. Pick {$records} to model it on, or describe what you want and let Ghostwriter choose the shape.",
            $group,
            [
                ['handle' => 'subject', 'label' => 'What is this about?', 'instructions' => $format === Format::Filament ? 'The subject, and what it is for.' : 'The subject, and what the entry is for.', 'type' => 'textarea', 'required' => true],
                ['handle' => 'reader', 'label' => 'Who is it for, and what should they do after reading?', 'type' => 'textarea', 'required' => true],
                ['handle' => 'points', 'label' => 'What must it say?', 'instructions' => 'The facts, figures, names and points to make. Nothing beyond these will be claimed.', 'type' => 'textarea', 'required' => true],
                ['handle' => 'shape', 'label' => 'Anything about its shape or length?', 'instructions' => "Leave blank to follow the {$records} it is modelled on.", 'type' => 'text'],
                ['handle' => 'must_not_appear', 'label' => 'What must not appear?', 'type' => 'textarea'],
            ],
            ($format === Format::Filament ? 'There is no set recipe for this.' : 'There is no set recipe for this entry.')
                ."\n\nIf example {$records} are shown, they were chosen as the model: follow their structure block for block and match their length. Keep unchanged any block that is identical across the examples, such as process steps or testimonials, and never reword a quotation.\n\nIf the brief asks for a different shape, the brief wins. With no examples to follow, choose the fields and blocks that suit what the brief asks for, preferring those this {$place} already uses.",
            ['Every fact comes from the brief or the conversation.', 'Its structure follows the chosen examples, or suits the brief where there are none.'],
        );
    }

    public function isGeneric(): bool
    {
        return str_starts_with($this->handle, self::GENERIC);
    }

    /**
     * The same kind, modelled on records chosen for one piece rather than
     * for the kind as a whole.
     *
     * @param  array<int, int|string>  $examples
     */
    public function modelledOn(array $examples): self
    {
        if ($examples === []) {
            return $this;
        }

        return new self($this->format, $this->handle, $this->title, $this->description, $this->group, $this->questions, $this->guidance, $this->checklist, $this->variant, [], $this->defaults, $examples);
    }

    /**
     * The kind as one session uses it: modelled on the records picked for
     * that piece, and on the blueprint or entry type of the record edited.
     */
    public function forSession(Session $session): self
    {
        $type = $this->modelledOn($session->examples);

        if ($session->variant === null || $session->variant === $type->variant) {
            return $type;
        }

        return new self($type->format, $type->handle, $type->title, $type->description, $type->group, $type->questions, $type->guidance, $type->checklist, $session->variant, $type->where, $type->defaults, $type->examples);
    }

    /**
     * Required answers that are missing, and answers too long, by question.
     *
     * @param  array<string, mixed>  $answers
     * @return array<string, string>
     */
    public function missing(array $answers): array
    {
        $errors = [];

        foreach ($this->questions as $question) {
            $handle = is_scalar($question['handle'] ?? null) ? (string) $question['handle'] : '';
            $answer = $answers[$handle] ?? null;

            if (($question['required'] ?? false) && (! is_scalar($answer) || trim((string) $answer) === '')) {
                $errors[$handle] = 'This needs an answer.';
            } elseif (is_scalar($answer) && mb_strlen((string) $answer) > 20000) {
                $errors[$handle] = 'Keep this under 20,000 characters.';
            }
        }

        return $errors;
    }

    /**
     * What the Studio's jobs take.
     */
    public function toStudio(): ContentKind
    {
        return ContentKind::fromArray($this->handle, $this->definition());
    }

    /**
     * A handle for a new kind from its title, apart from those taken: a
     * second "Guide" becomes guide-2. Statamic and Filament slug as Laravel
     * does (apostrophes dropped); Craft turns any other run of characters
     * into a hyphen.
     *
     * @param  array<int, string>  $taken
     */
    public static function handleFor(Format $format, string $title, string $fallback, array $taken): string
    {
        $base = match ($format) {
            Format::Statamic => self::slug($title) ?: $fallback,
            Format::Filament => self::slug($title !== '' ? $title : $fallback) ?: 'kind',
            Format::Craft => trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-') ?: $fallback,
        };

        $handle = $base;

        for ($n = 2; in_array($handle, $taken, true); $n++) {
            $handle = $base.'-'.$n;
        }

        return $handle;
    }

    /**
     * Laravel's Str::slug(), without its dependencies: ASCII, lower case,
     * hyphens between words, other characters dropped.
     */
    public static function slug(string $title): string
    {
        $ascii = function_exists('transliterator_transliterate')
            ? (string) transliterator_transliterate('Any-Latin; Latin-ASCII', $title)
            : (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title);

        $ascii = str_replace('@', '-at-', strtolower($ascii));
        $ascii = (string) preg_replace('/[^a-z0-9\-\s_]+/', '', $ascii);
        $ascii = (string) preg_replace('/[\-\s_]+/', '-', $ascii);

        return trim($ascii, '-');
    }

    /**
     * @return array<string, mixed>
     */
    private function encode(Format $format): array
    {
        if ($format !== Format::Filament) {
            return $this->definition($format);
        }

        return [
            'resource' => $this->group,
            'handle' => $this->handle,
            'title' => $this->title,
            'description' => $this->description,
            'definition' => $format->json($this->definition($format)),
        ];
    }
}
