<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras;

use NineteenNinetyFour\Ghostwriter\Core\Anchor\NormalisedText;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\ScopedEditCheck;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\SourceCheck;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Markers;
use NineteenNinetyFour\Ghostwriter\Core\Text\LenientYaml;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Reads the writer's `<extras>` block and keeps only what is sourced. No
 * model is involved.
 *
 * - **Unreadable** YAML drops the block, with a log line; the turn goes on.
 * - **Kinds** the site has no slot for are dropped, and only the first
 *   extra of each kind is kept.
 * - **Quotes.** An item's `source.quote` must appear in its source (the
 *   brief or answers, the draft, or the entry it names), compared after
 *   normalising case, whitespace, quotes, dashes and markdown.
 * - **Facts.** Every figure, quotation and name in the item's text (and its
 *   question, value, label, button) must be in that quote: ScopedEditCheck
 *   with the quote as what came before, so it adds no fact and no link.
 *   An attribution's facts must be in the source it quotes.
 * - **Asks.** An item that fails, or has no source, is kept only when it
 *   holds an `[[ask: …]]` and nothing else in it is unsourced against all
 *   the sources together: it is then an item that "needs your answer".
 *   Anything else is dropped. Facts are never invented.
 *
 * Ids are given after the drops: "x1", "x1.1"…
 */
final class ExtrasReader
{
    /** Words that say who someone is, not who: never a name in an attribution. */
    private const ROLES = ['a', 'an', 'the', 'client', 'customer', 'owner', 'homeowner', 'visitor', 'guest', 'member', 'resident', 'parent', 'student', 'patient', 'manager', 'director', 'founder', 'head', 'of', 'and', 'our', 'happy', 'local', 'satisfied'];

    private readonly LoggerInterface $logger;

    private readonly ScopedEditCheck $check;

    private readonly SourceCheck $sources;

    /**
     * Why each item was dropped in the last read(): "stats item 2: the quote is not in the draft".
     *
     * @var list<string>
     */
    public array $dropped = [];

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger;
        $this->sources = new SourceCheck;
        $this->check = new ScopedEditCheck($this->sources);
    }

    /**
     * @param  string|null  $block  The `<extras>` block's content (TaggedResponse::$extras).
     */
    public function read(?string $block, ExtraSlots $slots, ExtraSources $sources): Extras
    {
        $this->dropped = [];

        if ($block === null || trim($block) === '') {
            return new Extras;
        }

        $block = (string) preg_replace('/\A```(?:yaml|yml)?\s*\n(.*?)\n?```\s*\z/su', '$1', trim($block));

        try {
            $data = LenientYaml::parse($block);
        } catch (Throwable) {
            $this->logger->warning('Ghostwriter: the writer\'s extras could not be read and were left out.', ['agent' => 'writer']);

            return new Extras;
        }

        if (! is_array($data) || ! array_is_list($data)) {
            $this->logger->warning('Ghostwriter: the writer\'s extras were not a list and were left out.', ['agent' => 'writer']);

            return new Extras;
        }

        $extras = [];
        $seen = [];

        foreach ($data as $extra) {
            $kind = is_array($extra) && is_string($extra['kind'] ?? null) ? ExtraKind::tryFrom(trim($extra['kind'])) : null;

            if ($kind === null || ! $slots->has($kind) || isset($seen[$kind->value])) {
                $this->drop(($kind->value ?? 'an extra').': '.($kind === null ? 'not a kind of extra' : (isset($seen[$kind->value]) ? 'a second extra of this kind' : 'this site has no place for it')));

                continue;
            }

            $seen[$kind->value] = true;
            $id = 'x'.(count($extras) + 1);
            $items = [];

            foreach (is_array($extra['items'] ?? null) ? array_values($extra['items']) : [] as $i => $raw) {
                $item = is_array($raw) ? $this->item($raw, $kind, $sources, $kind->value.' item '.($i + 1)) : null;

                if ($item !== null) {
                    $items[] = $item->withId($id.'.'.(count($items) + 1));
                }
            }

            if ($items !== []) {
                $extras[] = new Extra($id, $kind, $items);
            }
        }

        if ($this->dropped !== []) {
            $this->logger->info('Ghostwriter: left out '.count($this->dropped).' extra item(s) without a source.', ['agent' => 'writer', 'dropped' => implode('; ', $this->dropped)]);
        }

        return new Extras($extras);
    }

    /**
     * @phpstan-impure
     *
     * @param  array<mixed>  $raw
     */
    private function item(array $raw, ExtraKind $kind, ExtraSources $sources, string $name): ?ExtraItem
    {
        $text = self::string($raw['text'] ?? ($raw['answer'] ?? null));
        $parts = [];

        foreach ($kind->parts() as $part) {
            $value = self::string($raw[$part] ?? null);

            if ($value !== '') {
                $parts[$part] = $value;
            }
        }

        if ($text === '' && $kind === ExtraKind::Stats && isset($parts['value'])) {
            $text = trim($parts['value'].' '.($parts['label'] ?? ''));
        }

        if ($text === '' || ($kind === ExtraKind::Faq && ! isset($parts['question']))) {
            return $this->drop("{$name}: it is empty");
        }

        $hints = array_values(array_column(Markers::asks(Markers::normalise($text."\n".implode("\n", $parts))), 'hint'));
        $text = Markers::normalise($text);
        $facts = self::facts($text, $parts);
        $source = is_array($raw['source'] ?? null) ? $this->source($raw['source'], $facts, $parts['attribution'] ?? '', $sources, $name) : null;

        if ($source !== null) {
            return new ExtraItem('', $text, $parts, $source, $hints);
        }

        if ($hints === []) {
            // A source that didn't hold has said why already.
            return is_array($raw['source'] ?? null) ? null : $this->drop("{$name}: it has no source");
        }

        // An item that needs the editor's answer may say nothing else unsourced.
        $all = $sources->all();

        if ($this->check->check('', trim($facts."\n".($parts['attribution'] ?? '')), $all, 0.0, PHP_FLOAT_MAX, mayAddMarkers: true) !== [] || self::unnamed($parts['attribution'] ?? '', implode("\n", $all)) !== []) {
            return $this->drop("{$name}: it asks for a fact but invents another");
        }

        return new ExtraItem('', $text, $parts, null, $hints);
    }

    /**
     * The item's source, when its quote is there and holds the item's facts.
     *
     * @param  array<mixed>  $raw
     */
    private function source(array $raw, string $facts, string $attribution, ExtraSources $sources, string $name): ?Source
    {
        $kind = SourceKind::tryFrom(strtolower(self::string($raw['from'] ?? ($raw['kind'] ?? ''))));
        $quote = self::string($raw['quote'] ?? null);
        $ref = self::string($raw['ref'] ?? null);

        if ($kind === null || $kind === SourceKind::Editor || $quote === '') {
            $this->dropped[] = "{$name}: its source is not one it may use";

            return null;
        }

        $texts = $sources->texts($kind);

        if ($kind === SourceKind::Entry && $ref !== '' && isset($texts[$ref])) {
            $texts = [$ref => $texts[$ref]];
        }

        $needle = mb_strtolower(NormalisedText::string($quote, true));
        $found = null;

        foreach ($texts as $key => $text) {
            if ($needle !== '' && str_contains(mb_strtolower(NormalisedText::string($text, true)), $needle)) {
                $found = (string) $key;

                break;
            }
        }

        if ($found === null) {
            $this->dropped[] = "{$name}: the quote is not in the {$kind->value}";

            return null;
        }

        // The quote is what the item may say: no fact or link beyond it.
        $problems = $this->check->check($quote, $facts, [], 0.0, PHP_FLOAT_MAX, mayAddMarkers: true);

        if (in_array(ScopedEditCheck::FACTS, $problems, true) || in_array(ScopedEditCheck::LINK, $problems, true)) {
            $this->dropped[] = "{$name}: it says more than its quote";

            return null;
        }

        if ($attribution !== '' && ($this->sources->unsourced($attribution, [$texts[$found] ?? '']) !== [] || self::unnamed($attribution, $texts[$found] ?? '') !== [])) {
            $this->dropped[] = "{$name}: its attribution is not in the {$kind->value}";

            return null;
        }

        if ($kind === SourceKind::Entry) {
            $entry = $sources->entries[$found] ?? null;

            return new Source($kind, $quote, $found, $entry['id'] ?? null, $entry['title'] ?? null);
        }

        return new Source($kind, $quote, $ref !== '' ? $ref : null);
    }

    /**
     * What an item states: its text and the parts that state something.
     *
     * @param  array<string, string>  $parts
     */
    private static function facts(string $text, array $parts): string
    {
        $stated = array_diff_key($parts, array_flip([...ExtraKind::addresses(), 'attribution']));

        return trim(implode("\n", [...array_values($stated), $text]));
    }

    /**
     * The capitalised words of an attribution that its source doesn't have:
     * a person's or a place's name. Words that only say who someone is
     * ("Client", "Homeowner") are not names. SourceCheck leaves lines in
     * title case alone, which an attribution often is, so it is checked
     * word by word here.
     *
     * @return list<string>
     */
    public static function unnamed(string $attribution, string $source): array
    {
        $known = array_flip(NormalisedText::words($source));
        $missing = [];

        foreach (preg_split('/[^\p{L}\p{N}\'’-]+/u', $attribution, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $lower = mb_strtolower($word);

            if (preg_match('/^\p{Lu}/u', $word) === 1 && ! in_array($lower, self::ROLES, true) && ! isset($known[$lower])) {
                $missing[] = $word;
            }
        }

        return $missing;
    }

    /** @phpstan-impure */
    private function drop(string $why): null
    {
        $this->dropped[] = $why;

        return null;
    }

    private static function string(mixed $value): string
    {
        return is_scalar($value) && ! is_bool($value) ? trim((string) $value) : '';
    }
}
