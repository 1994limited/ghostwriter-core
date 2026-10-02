<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps;

/**
 * Whether an entry may go live, as PublishReadiness found it: the gaps that
 * block (unlicensed stock previews among them), and what to tell the
 * editor, once for the whole page and once per field.
 *
 * Gaps the CMS requires anyway aren't repeated: its own validation
 * reports them.
 */
final class Readiness
{
    /**
     * @param  list<Gap>  $problems
     */
    public function __construct(
        private readonly array $problems,
        public readonly OnPublish $mode,
        private readonly GapReport $report = new GapReport,
    ) {}

    /**
     * The gaps that stop the page going live.
     *
     * @return list<Gap>
     */
    public function problems(): array
    {
        return $this->problems;
    }

    public function ready(): bool
    {
        return $this->problems === [];
    }

    /** Publishing is refused. */
    public function blocked(): bool
    {
        return ! $this->ready() && $this->mode === OnPublish::Block;
    }

    /** Publishing goes through, with a warning that lists the gaps. */
    public function warns(): bool
    {
        return ! $this->ready() && $this->mode === OnPublish::Warn;
    }

    /** Everything the finder found, for the pill and the guide. */
    public function report(): GapReport
    {
        return $this->report;
    }

    /**
     * One message for the page: "3 things to finish before this page goes
     * live: Hero: Intro (adult ticket price); …", or, in warn mode,
     * "Published with 3 things still to finish: …".
     *
     * @param  (callable(Message): string)|null  $translate  The addon's translator; English by default.
     */
    public function message(?callable $translate = null): Message
    {
        $count = count($this->problems);

        if ($count === 0) {
            return new Message('gaps.publish.ready');
        }

        $translate ??= fn (Message $message) => $message->english();
        $items = implode('; ', array_map(fn (Gap $gap) => $translate(self::item($gap)), $this->problems));
        $key = 'gaps.publish.'.($this->mode === OnPublish::Block ? 'blocked' : 'warned').($count === 1 ? '-one' : '');

        return new Message($key, ['count' => $count, 'items' => $items]);
    }

    /**
     * What each field with a problem says, for the CMS's field errors:
     * keyed by the form's dotted path (`page_builder.1.intro`), or with
     * `$topLevel` by the top-level field's handle (Craft's addError()).
     * Several problems in one field are one message.
     *
     * @param  (callable(Message): string)|null  $translate  The addon's translator; English by default.
     * @return array<string, string>
     */
    public function byField(?callable $translate = null, bool $topLevel = false): array
    {
        $translate ??= fn (Message $message) => $message->english();
        $fields = [];

        foreach ($this->messages($topLevel) as $key => $messages) {
            $fields[$key] = implode(' ', array_unique(array_map(fn (Message $message) => $translate($message), $messages)));
        }

        return $fields;
    }

    /**
     * The same, untranslated.
     *
     * @return array<string, list<Message>>
     */
    public function messages(bool $topLevel = false): array
    {
        $fields = [];

        foreach ($this->problems as $gap) {
            $fields[$topLevel ? $gap->path->handle() : $gap->path->dotted()][] = self::fieldMessage($gap);
        }

        return $fields;
    }

    /**
     * @return array{ready: bool, blocked: bool, mode: string, message: array{key: string, params: array<string, scalar|null>}, problems: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'ready' => $this->ready(),
            'blocked' => $this->blocked(),
            'mode' => $this->mode->value,
            'message' => $this->message()->toArray(),
            'problems' => array_map(fn (Gap $gap) => $gap->toArray(), $this->problems),
        ];
    }

    /** One gap in the page's list: "Hero: Intro (adult ticket price)". */
    private static function item(Gap $gap): Message
    {
        return new Message('gaps.publish.item.'.$gap->kind->value, array_filter([
            'label' => $gap->label,
            'hint' => $gap->hint,
            'library' => is_scalar($gap->meta['library'] ?? null) ? $gap->meta['library'] : null,
        ], fn ($value) => $value !== null));
    }

    /** What the field says: "Add the adult ticket price before publishing." */
    private static function fieldMessage(Gap $gap): Message
    {
        return new Message('gaps.publish.field.'.$gap->kind->value, array_filter([
            'label' => $gap->label,
            'hint' => $gap->hint,
            'library' => is_scalar($gap->meta['library'] ?? null) ? $gap->meta['library'] : null,
        ], fn ($value) => $value !== null));
    }
}
