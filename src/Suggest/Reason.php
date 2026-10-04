<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\Message;

/**
 * Why a suggestion is made, and where that comes from. A model-written
 * reason has `text`; a free finding's has its `message` instead (a key,
 * translated by the addon).
 */
final class Reason
{
    /**
     * @param  ?string  $detail  The voice guide's heading, the checklist item, the finding's kind.
     * @param  ?string  $entry  SiteEntry: the entry's key (EntryRef::key()).
     * @param  ?string  $title  SiteEntry: the entry's title.
     */
    public function __construct(
        public readonly string $text,
        public readonly ReasonSource $source,
        public readonly ?string $detail = null,
        public readonly ?string $entry = null,
        public readonly ?Message $message = null,
        public readonly ?string $title = null,
    ) {}

    /** The source line: 'suggest.source.<source>', with the heading or title. */
    public function sourceLabel(): Message
    {
        return match ($this->source) {
            ReasonSource::VoiceGuide => $this->detail !== null && $this->detail !== ''
                ? new Message('suggest.source.voice-guide', ['heading' => $this->detail])
                : new Message('suggest.source.voice-guide-plain'),
            ReasonSource::Kind => new Message('suggest.source.kind', ['kind' => $this->detail]),
            ReasonSource::SiteEntry => new Message('suggest.source.site-entry', ['title' => $this->title ?? $this->entry]),
            default => new Message('suggest.source.'.$this->source->value),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'text' => $this->text,
            'source' => $this->source->value,
            'detail' => $this->detail,
            'entry' => $this->entry,
            'title' => $this->title,
            'message' => $this->message?->toArray(),
            'label' => $this->sourceLabel()->toArray(),
        ], fn ($value) => $value !== null);
    }

    /**
     * @param  array<mixed>  $array
     */
    public static function fromArray(array $array): self
    {
        $message = is_array($array['message'] ?? null) ? $array['message'] : null;
        $params = is_array($message['params'] ?? null) ? array_filter($message['params'], fn ($value) => is_scalar($value) || $value === null) : [];
        $string = fn (string $key) => is_string($array[$key] ?? null) ? $array[$key] : null;

        return new self(
            $string('text') ?? '',
            ReasonSource::tryFrom($string('source') ?? '') ?? ReasonSource::General,
            $string('detail'),
            $string('entry'),
            $message === null ? null : new Message(is_string($message['key'] ?? null) ? $message['key'] : '', array_combine(array_map('strval', array_keys($params)), array_values($params))),
            $string('title'),
        );
    }
}
