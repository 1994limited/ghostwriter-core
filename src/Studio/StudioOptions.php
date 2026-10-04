<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Studio;

use InvalidArgumentException;

/**
 * The few things the three addons' Studios did differently, as settings.
 * statamic(), craft() and filament() give each addon's behaviour as it was
 * before core; docs/studio-unification.md lists what each one changes.
 */
final class StudioOptions
{
    /**
     * Agents whose reply is no use part-written (core-ai-design §6.6): a
     * cut-off draft or guide is reported rather than kept. Every other
     * agent keeps what came back.
     */
    public const WHOLE = ['writer', 'type-analyst', 'voice-analyst', 'voice-editor', 'reviser'];

    /** What the person is told when one of the WHOLE agents is cut off even with more room. */
    public const CUT_OFF_MESSAGES = [
        'writer' => 'The draft was longer than Ghostwriter allows and was cut off. Try asking for a shorter piece.',
        'type-analyst' => 'The description of this kind of content was longer than Ghostwriter allows and was cut off. Try again.',
        'voice-analyst' => 'The voice guide was longer than Ghostwriter allows and was cut off. Try again.',
        'voice-editor' => 'The voice guide was longer than Ghostwriter allows and was cut off. Try asking for a shorter guide.',
        'reviser' => 'The revision was longer than Ghostwriter allows and was cut off. Try applying fewer comments at a time.',
    ];

    /** @var array<string, string> */
    public readonly array $cutOffMessages;

    /**
     * @param  bool  $logReplies  Put the model's whole reply in the log when it can't be read (F8). Off by default: only the problem is logged. Prompts and keys are never logged.
     * @param  int  $exampleDepth  How deep example entries are written out as YAML before nesting goes inline.
     * @param  string  $unpublishedLabel  How the planner is told an entry isn't live: "- Title (draft)".
     * @param  string  $variantLabel  What a blueprint or entry type is called in the kind finder's lines: "- id 12 · "Title" · entry type: News".
     * @param  bool  $missingKindsIsEmpty  A kind finder reply with no `<kinds>` block means "nothing to add" rather than an unreadable reply.
     * @param  array<string, string>  $cutOffMessages  Replaces some or all of CUT_OFF_MESSAGES; keys from WHOLE.
     */
    public function __construct(
        public readonly bool $logReplies = false,
        public readonly int $exampleDepth = 100,
        public readonly string $unpublishedLabel = 'draft',
        public readonly string $variantLabel = 'blueprint',
        public readonly bool $missingKindsIsEmpty = false,
        array $cutOffMessages = [],
    ) {
        $unknown = array_diff(array_keys($cutOffMessages), self::WHOLE);

        if ($unknown !== []) {
            throw new InvalidArgumentException('Only '.implode(', ', self::WHOLE).' are reported when cut off, not '.implode(', ', $unknown).'.');
        }

        if ($exampleDepth < 1) {
            throw new InvalidArgumentException('The example depth must be at least 1.');
        }

        $this->cutOffMessages = $cutOffMessages + self::CUT_OFF_MESSAGES;
    }

    /**
     * Statamic: examples dumped as its YAML facade did (depth 100), and a
     * reply with no `<kinds>` block taken as "nothing to add", as its
     * kind-finder prompt invites.
     */
    public static function statamic(bool $logReplies = false): self
    {
        return new self($logReplies, 100, 'draft', 'blueprint', true, [
            'voice-analyst' => 'The voice guide was longer than Ghostwriter allows and was cut off. Try again, or read fewer collections.',
        ]);
    }

    /**
     * Craft: examples at depth 12, entry types rather than blueprints, and
     * one message for every cut-off reply.
     */
    public static function craft(bool $logReplies = false): self
    {
        $message = 'The answer ran past its length limit and was cut off before it finished. Try asking for something shorter.';

        return new self($logReplies, 12, 'draft', 'entry type', false, array_fill_keys(self::WHOLE, $message));
    }

    /**
     * Filament: examples at depth 20, and records that aren't live are
     * "not published".
     */
    public static function filament(bool $logReplies = false): self
    {
        return new self($logReplies, 20, 'not published', 'blueprint', false, [
            'type-analyst' => 'The description of this kind of content was longer than Ghostwriter allows and was cut off. Try again, or model it on fewer records.',
            'voice-editor' => 'The voice guide was longer than Ghostwriter allows and was cut off. Try asking for a smaller change.',
        ]);
    }

    public function withLogReplies(bool $logReplies = true): self
    {
        return new self($logReplies, $this->exampleDepth, $this->unpublishedLabel, $this->variantLabel, $this->missingKindsIsEmpty, $this->cutOffMessages);
    }
}
