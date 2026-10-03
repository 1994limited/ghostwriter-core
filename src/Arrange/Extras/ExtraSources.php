<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Message;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Conversation;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Layout;
use Symfony\Component\Yaml\Yaml;

/**
 * What an extra's facts may come from, as text: the colleague's own words
 * (the brief, the questionnaire answers and every message they sent), the
 * draft, and the existing entries the writer was shown, by their number
 * in the prompt. Only entries the writer was shown count.
 */
final class ExtraSources
{
    /**
     * @param  list<string>  $brief  The colleague's words: the brief, the answers, their messages.
     * @param  array<int|string, array{text: string, id: int|string|null, title: string|null}>  $entries  By the example's number ("1", "2").
     * @param  list<string>  $conversation  Comments and other messages, for the `conversation` kind.
     */
    public function __construct(
        public readonly array $brief = [],
        public readonly string $draft = '',
        public readonly array $entries = [],
        public readonly array $conversation = [],
    ) {}

    /**
     * The sources of a writer's turn: the person's messages and answers, the
     * draft it handed back (or the one it had), and the examples it was
     * shown. `$exampleIds` are the examples' entry ids, in the same order,
     * when the adapter knows them (for the link on the source label).
     *
     * @param  array<int, int|string|null>  $exampleIds
     */
    public static function fromWriter(Conversation $conversation, ?string $draft, Layout $layout, array $exampleIds = []): self
    {
        $brief = [];

        foreach ($conversation->messages as $message) {
            if ($message instanceof Message && $message->role === 'user' && trim($message->content) !== '') {
                $brief[] = $message->content;
            }
        }

        foreach ($conversation->answers as $answer) {
            if (is_scalar($answer) && trim((string) $answer) !== '') {
                $brief[] = (string) $answer;
            } elseif (is_array($answer)) {
                $brief[] = implode("\n", array_map('strval', array_filter($answer, 'is_scalar')));
            }
        }

        $entries = [];
        $ids = array_values($exampleIds);

        foreach (array_values($layout->examples) as $i => $example) {
            $title = $example['title'] ?? null;
            $entries[(string) ($i + 1)] = [
                'text' => Yaml::dump($example, 100, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK),
                'id' => $ids[$i] ?? null,
                'title' => is_scalar($title) ? (string) $title : null,
            ];
        }

        return new self($brief, $draft ?? $conversation->draft ?? '', $entries);
    }

    /**
     * The texts a source of this kind may quote from, by ref. An entry's
     * ref is its number; the others have one text each.
     *
     * @return array<int|string, string>
     */
    public function texts(SourceKind $kind): array
    {
        return match ($kind) {
            SourceKind::Brief, SourceKind::Answer => ['' => implode("\n\n", $this->brief)],
            SourceKind::Draft => ['' => $this->draft],
            SourceKind::Entry => array_map(fn (array $entry) => $entry['text'], $this->entries),
            SourceKind::Conversation => ['' => implode("\n\n", [...$this->conversation, ...$this->brief])],
            SourceKind::Editor => [],
        };
    }

    /**
     * Everything at once: what an item with no source of its own may still
     * rely on (the words around an ask).
     *
     * @return list<string>
     */
    public function all(): array
    {
        return array_values(array_filter([...$this->brief, $this->draft, ...array_column($this->entries, 'text'), ...$this->conversation], fn (string $text) => trim($text) !== ''));
    }
}
