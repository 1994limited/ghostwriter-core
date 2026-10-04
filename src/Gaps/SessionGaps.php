<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Layout\BuiltEntry;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SeoState;

/**
 * The gap list a session keeps from the moment a draft was applied
 * (Session::$gaps): what the writer asked for, the places the builder and
 * the house style left for a person, and where placeholders went.
 *
 * It only makes the guide's messages better (enrich()) and says which
 * empty fields the draft meant to have filled (expects()). The content is
 * the source of truth: an entry here whose marker has gone is simply never
 * matched, and a marker with no entry here still gets its default message.
 *
 * Each entry is `{kind, path?, label?, hint?, reason?}`, with `kind` one of
 * GapKind's values or `place` (a reference still to choose).
 *
 * It also carries the links the SEO pass added to the session's draft
 * (Seo\SeoState::$links), so Finish this page can ask the editor to check
 * them (Detectors\AddedLinks).
 */
final class SessionGaps
{
    /** A reference to choose that the draft left for a person. */
    public const PLACE = 'place';

    /** The reason given for anything the draft left out. */
    public const FROM_DRAFT = 'draft';

    /** @var list<array{kind: string, path: string|null, label: string|null, hint: string|null, reason: string|null}> */
    public readonly array $entries;

    /** @var list<array{unit: string, words: string, href: string, title: string, type: string, url: ?string, why: string}> The links the SEO pass added. */
    public readonly array $links;

    /**
     * @param  array<int, mixed>  $entries
     * @param  array<int, mixed>  $links  Seo\SeoState::$links.
     */
    public function __construct(array $entries = [], array $links = [])
    {
        $this->links = SeoState::fromArray(['links' => $links])->links;

        $clean = [];

        foreach ($entries as $entry) {
            if (! is_array($entry) || ! is_string($entry['kind'] ?? null)) {
                continue;
            }

            $clean[] = [
                'kind' => $entry['kind'],
                'path' => self::string($entry['path'] ?? null),
                'label' => self::string($entry['label'] ?? null),
                'hint' => self::string($entry['hint'] ?? null),
                'reason' => self::string($entry['reason'] ?? null),
            ];
        }

        $this->entries = $clean;
    }

    public static function fromSession(Session $session): self
    {
        return new self($session->gaps, SeoState::of($session)->links);
    }

    /**
     * The list for a draft being applied: its asks for fields that can't
     * hold text, the references still to choose, the house style's places
     * (by label, as HouseResult::$toFill names them) and the image
     * placeholders put in (Placeholders::filled()).
     *
     * @param  array<int, string>  $housePlaces
     * @param  array<int, string>  $placeholders
     */
    public static function fromDraft(BuiltEntry $built, array $housePlaces = [], array $placeholders = []): self
    {
        $entries = [];

        foreach ($built->asks as $ask) {
            $entries[] = ['kind' => GapKind::AskValue->value, 'path' => $ask['path'], 'label' => $ask['label'], 'hint' => $ask['hint'], 'reason' => self::FROM_DRAFT];
        }

        foreach ($built->toFill as $place) {
            $entries[] = ['kind' => self::PLACE, 'path' => $place['path'], 'label' => $place['label'], 'reason' => self::FROM_DRAFT];
        }

        foreach ($housePlaces as $label) {
            $entries[] = ['kind' => self::PLACE, 'label' => preg_replace('/ \((?:links to example\.com for now|link still to choose)\)$/', '', $label), 'reason' => self::FROM_DRAFT];
        }

        foreach ($placeholders as $label) {
            $entries[] = ['kind' => GapKind::ImagePlaceholder->value, 'label' => $label, 'reason' => self::FROM_DRAFT];
        }

        return new self($entries);
    }

    /**
     * The facts meant for fields that can't hold text.
     *
     * @return list<array{path: string, label: string, hint: string}>
     */
    public function askValues(): array
    {
        $asks = [];

        foreach ($this->entries as $entry) {
            if ($entry['kind'] === GapKind::AskValue->value && $entry['path'] !== null) {
                $asks[] = ['path' => $entry['path'], 'label' => $entry['label'] ?? $entry['path'], 'hint' => $entry['hint'] ?? ''];
            }
        }

        return $asks;
    }

    /** Whether the draft meant this place to be filled by a person. */
    public function expects(FieldPath $path, string $label = ''): bool
    {
        foreach ($this->entries as $entry) {
            if ($entry['kind'] !== self::PLACE && $entry['kind'] !== GapKind::AskValue->value) {
                continue;
            }

            if ($entry['path'] !== null ? $entry['path'] === $path->toString() : ($label !== '' && $entry['label'] === $label)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The gap with what the session knows about it: why it was left
     * (`meta.reason`) and that the draft left it (`meta.fromDraft`).
     * Matched by kind and path (and hint), else by kind and label.
     */
    public function enrich(Gap $gap): Gap
    {
        foreach ($this->entries as $entry) {
            $kinds = [$gap->kind->value];

            if (in_array($gap->kind, [GapKind::ImageEmpty, GapKind::LinkEmpty, GapKind::Required, GapKind::Expected], true)) {
                $kinds[] = self::PLACE;
            }

            if (! in_array($entry['kind'], $kinds, true)) {
                continue;
            }

            $samePlace = $entry['path'] !== null ? $entry['path'] === $gap->path->toString() : $entry['label'] !== null && $entry['label'] === $gap->label;
            $sameHint = $entry['hint'] === null || $gap->hint === null || Markers::normaliseHint($entry['hint']) === Markers::normaliseHint($gap->hint);

            if ($samePlace && $sameHint) {
                return $gap->withMeta(['reason' => $entry['reason'] ?? self::FROM_DRAFT, 'fromDraft' => true]);
            }
        }

        return $gap;
    }

    public function isEmpty(): bool
    {
        return $this->entries === [] && $this->links === [];
    }

    /**
     * For Session::$gaps: only the keys that are set.
     *
     * @return list<array<string, string>>
     */
    public function toArray(): array
    {
        return array_map(fn (array $entry) => array_filter($entry, fn ($value) => $value !== null), $this->entries);
    }

    private static function string(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
