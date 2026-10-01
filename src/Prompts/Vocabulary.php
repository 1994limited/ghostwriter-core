<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Prompts;

use InvalidArgumentException;

/**
 * The words a prompt uses for the host CMS: what the whole thing is called
 * (a website, an app), what content is grouped into (sections, resources)
 * and what one piece of content is (an entry, a record).
 *
 * Prompts mark these with `[[name]]` placeholders, which PromptLibrary fills
 * from terms(). The double square brackets keep them apart from the
 * `{{ name }}` placeholders each addon fills itself with strtr().
 *
 * | Placeholder            | Property / phrase      |
 * |------------------------|------------------------|
 * | `[[place]]`            | $place                 |
 * | `[[site]]`             | $site                  |
 * | `[[group]]`            | $group                 |
 * | `[[groups]]`           | $groups                |
 * | `[[group_key]]`        | $groupKey              |
 * | `[[item]]`             | $item                  |
 * | `[[items]]`            | $items                 |
 * | `[[readers]]`          | phrase, "the {site}'s readers" |
 * | `[[offerer]]`          | phrase, "the {site}"   |
 * | `[[on_place]]`         | phrase, "on the {site}" |
 * | `[[kind_ids]]`         | phrase, from $numericIds |
 * | `[[kind_ids_example]]` | phrase, from $numericIds |
 * | `[[kinds_when_none]]`  | phrase, empty by default |
 *
 * Phrases have defaults built from the properties; an addon whose wording
 * differs passes its own in `$phrases`. statamic(), craft() and filament()
 * give each addon's wording exactly as its prompts had it before core.
 */
final class Vocabulary
{
    /** Phrases a vocabulary may set, with what each is for. */
    public const PHRASES = [
        'readers' => 'Who reads the content: "the site\'s readers".',
        'offerer' => 'Who offers the services written about: "the site".',
        'on_place' => 'Where existing content is: "on the site".',
        'kind_ids' => 'How the kind finder lists example IDs: "a list of numbers".',
        'kind_ids_example' => 'The example ID list in the kind finder\'s answer format.',
        'kinds_when_none' => 'Appended to the kind finder\'s rule about kinds already taught. Empty by default.',
    ];

    /** @var array<string, string> */
    public readonly array $phrases;

    /**
     * @param  string  $place  What the whole thing is: "website", "app".
     * @param  string  $site  Its short name: "site", "app".
     * @param  string  $group  What content is grouped into: "section", "resource".
     * @param  string  $groups  The plural of $group.
     * @param  string  $groupKey  The YAML key the planner answers with: "collection", "resource".
     * @param  string  $item  One piece of content: "entry", "record".
     * @param  string  $items  The plural of $item.
     * @param  bool  $numericIds  Content IDs are numbers, rather than strings to be copied exactly.
     * @param  array<string, string>  $phrases  Wording that isn't built from the words above; keys from PHRASES.
     */
    public function __construct(
        public readonly string $place,
        public readonly string $site,
        public readonly string $group,
        public readonly string $groups,
        public readonly string $groupKey,
        public readonly string $item,
        public readonly string $items,
        public readonly bool $numericIds = false,
        array $phrases = [],
    ) {
        $unknown = array_diff(array_keys($phrases), array_keys(self::PHRASES));

        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown vocabulary phrase: '.implode(', ', $unknown).'. Known: '.implode(', ', array_keys(self::PHRASES)).'.');
        }

        $this->phrases = $phrases + [
            'readers' => "the {$site}'s readers",
            'offerer' => "the {$site}",
            'on_place' => "on the {$site}",
            'kind_ids' => $numericIds ? 'a list of numbers' : 'a list of strings, exactly as given',
            'kind_ids_example' => $numericIds ? '[12, 15]' : '["id-one", "id-two"]',
            'kinds_when_none' => '',
        ];
    }

    /**
     * Statamic: a website of sections holding entries. Entry IDs are UUID
     * strings. The planner answers with a `collection` key.
     */
    public static function statamic(): self
    {
        return new self('website', 'site', 'section', 'sections', 'collection', 'entry', 'entries', phrases: [
            'kinds_when_none' => ' If everything the section holds is already taught or turned down, reply with an empty `<kinds></kinds>` block and nothing else.',
        ]);
    }

    /**
     * Craft: a website of sections holding entries, with numeric IDs. The
     * planner answers with a `collection` key, as Craft's planner always has.
     */
    public static function craft(): self
    {
        return new self('website', 'site', 'section', 'sections', 'collection', 'entry', 'entries', numericIds: true);
    }

    /**
     * Filament: an app of resources holding records.
     */
    public static function filament(): self
    {
        return new self('app', 'app', 'resource', 'resources', 'resource', 'record', 'records', phrases: [
            'readers' => 'its readers',
            'offerer' => 'the organisation',
            'on_place' => 'in the app',
        ]);
    }

    /**
     * @return array<string, string> Placeholder => text, ready for strtr().
     */
    public function terms(): array
    {
        $terms = [
            '[[place]]' => $this->place,
            '[[site]]' => $this->site,
            '[[group]]' => $this->group,
            '[[groups]]' => $this->groups,
            '[[group_key]]' => $this->groupKey,
            '[[item]]' => $this->item,
            '[[items]]' => $this->items,
        ];

        foreach ($this->phrases as $name => $text) {
            $terms["[[{$name}]]"] = $text;
        }

        return $terms;
    }
}
