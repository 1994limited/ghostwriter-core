<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Studio;

use NineteenNinetyFour\Ghostwriter\Core\Text\LenientYaml;
use Throwable;

/**
 * What the writer asks before drafting, as a short intro and a few short
 * questions, each answered in its own box (since 1.7).
 *
 * The writer sends them in a `<questions>` block of YAML beside its
 * `<reply>`, which is the intro (writer.md):
 *
 *     - id: project
 *       question: Which real project should this be about?
 *       hint: The Harbour Street refit, say
 *     - id: phasing
 *       question: Do you phase larger projects?
 *       kind: choice
 *       options: [Yes, in stages, No, all at once]
 *       optional: true
 *
 * Session::answer() keeps them on the writer's message under `asked`
 * (toArray()), with the intro and a numbered list as its `content`, so the
 * conversation reads as it always did and the writer sees what it asked.
 * The person's answers go back as one message (reply()): "question →
 * answer" pairs, a skipped one as "skipped", with the answers under
 * `answers`. present() pairs the two for a panel. A message from before,
 * with no `asked`, is the plain text it always was.
 */
final class Asks
{
    /** The most questions asked in one go. */
    public const MAX = 4;

    /** The most set answers a `choice` question offers. */
    public const MAX_OPTIONS = 6;

    /** The key on the writer's message that holds them. */
    public const KEY = 'asked';

    /** The key on the person's message that holds the answers. */
    public const ANSWERS = 'answers';

    /** The key on the person's message that holds anything else they added. */
    public const MORE = 'more';

    /**
     * @param  list<AskedQuestion>  $questions
     */
    public function __construct(
        public readonly string $intro,
        public readonly array $questions,
    ) {}

    /**
     * The questions in a `<questions>` block, with the reply as the intro.
     * Null when there is no block, or nothing in it can be read: the
     * reply is then plain text, as before.
     */
    public static function read(?string $block, string $intro = ''): ?self
    {
        if ($block === null || trim($block) === '') {
            return null;
        }

        try {
            $data = LenientYaml::parse(self::unfenced($block));
        } catch (Throwable) {
            return null;
        }

        if (is_array($data) && isset($data['questions']) && is_array($data['questions'])) {
            $intro = $intro !== '' ? $intro : self::string($data['intro'] ?? '');
            $data = $data['questions'];
        }

        if (! is_array($data) || ! array_is_list($data)) {
            return null;
        }

        $questions = [];
        $ids = [];

        foreach ($data as $item) {
            $question = self::question($item, count($questions) + 1, $ids);

            if ($question !== null) {
                $questions[] = $question;
                $ids[] = $question->id;
            }

            if (count($questions) === self::MAX) {
                break;
            }
        }

        return $questions === [] ? null : new self(trim($intro), $questions);
    }

    /**
     * The questions kept on a writer's message, or null for one without.
     *
     * @param  array<string, mixed>|null  $message
     */
    public static function fromMessage(?array $message): ?self
    {
        $asked = $message[self::KEY] ?? null;

        if (! is_array($asked) || ! is_array($asked['questions'] ?? null)) {
            return null;
        }

        $questions = [];

        foreach ($asked['questions'] as $item) {
            if (is_array($item) && ($text = self::string($item['question'] ?? '')) !== '' && ($id = self::string($item['id'] ?? '')) !== '') {
                $options = self::options($item['options'] ?? []);
                $choice = ($item['kind'] ?? null) === AskedQuestion::CHOICE && count($options) >= 2;
                $questions[] = new AskedQuestion($id, $text, self::string($item['hint'] ?? ''), $choice ? AskedQuestion::CHOICE : AskedQuestion::TEXT, $choice ? $options : [], (bool) ($item['optional'] ?? false));
            }
        }

        return $questions === [] ? null : new self(self::string($asked['intro'] ?? ''), $questions);
    }

    /**
     * @return array{intro: string, questions: list<array{id: string, question: string, hint: string, kind: string, options: list<string>, optional: bool}>}
     */
    public function toArray(): array
    {
        return [
            'intro' => $this->intro,
            'questions' => array_map(fn (AskedQuestion $question) => $question->toArray(), $this->questions),
        ];
    }

    /**
     * The intro and the questions as plain text: the message's `content`,
     * which the writer sees on its next turn and any older panel shows.
     */
    public function text(): string
    {
        $lines = [];

        foreach ($this->questions as $i => $question) {
            $lines[] = ($i + 1).'. '.$question->question;
        }

        return trim($this->intro."\n\n".implode("\n", $lines));
    }

    /**
     * The person's message answering them: each question with its answer,
     * "skipped" for one left empty, then anything else they added. The
     * writer reads it as it is; the answers are kept under `answers` and
     * `more` for the panel.
     *
     * @param  array<string, string|null>  $answers  By question id; null or empty is skipped.
     * @return array{content: string, extra: array<string, mixed>}
     */
    public function reply(array $answers, string $more = '', string $skipped = 'skipped', string $also = 'Also:'): array
    {
        $pairs = [];
        $kept = [];

        foreach ($this->questions as $question) {
            $answer = trim((string) ($answers[$question->id] ?? ''));
            $pairs[] = $question->question.' → '.($answer !== '' ? $answer : $skipped);
            $kept[] = ['id' => $question->id, 'question' => $question->question, 'answer' => $answer !== '' ? $answer : null];
        }

        $more = trim($more);
        $content = implode("\n\n", $pairs).($more !== '' ? "\n\n{$also} {$more}" : '');
        $extra = [self::ANSWERS => $kept];

        if ($more !== '') {
            $extra[self::MORE] = $more;
        }

        return ['content' => $content, 'extra' => $extra];
    }

    /**
     * Whether every question was left unanswered and nothing else was said.
     *
     * @param  array<string, string|null>  $answers
     */
    public function unanswered(array $answers, string $more = ''): bool
    {
        foreach ($this->questions as $question) {
            if (trim((string) ($answers[$question->id] ?? '')) !== '') {
                return false;
            }
        }

        return trim($more) === '';
    }

    /**
     * A writer's message with questions, as a panel shows it: the intro and
     * each question with the answer given in the next message, if that is
     * the person's answers. Null for a message without questions.
     *
     * @param  array<string, mixed>  $message
     * @param  array<string, mixed>|null  $next  The message after it.
     * @return array{intro: string, answered: bool, questions: list<array{id: string, question: string, hint: string, kind: string, options: list<string>, optional: bool, answer: string|null}>}|null
     */
    public static function present(array $message, ?array $next = null): ?array
    {
        $asks = self::fromMessage($message);

        if ($asks === null) {
            return null;
        }

        $given = self::answersIn($next);
        $questions = [];

        foreach ($asks->questions as $question) {
            $questions[] = $question->toArray() + ['answer' => $given === null ? null : ($given[$question->id] ?? null)];
        }

        return ['intro' => $asks->intro, 'answered' => $given !== null, 'questions' => $questions];
    }

    /**
     * Whether a message is the person's answers to the questions before it.
     * A panel shows them in the questions' card, and only `more` (if any)
     * as a message of its own.
     *
     * @param  array<string, mixed>|null  $message
     */
    public static function isAnswers(?array $message): bool
    {
        return self::answersIn($message) !== null;
    }

    /**
     * @param  array<string, mixed>|null  $message
     * @return array<string, string|null>|null
     */
    private static function answersIn(?array $message): ?array
    {
        if ($message === null || ($message['role'] ?? null) !== 'user' || ! is_array($message[self::ANSWERS] ?? null)) {
            return null;
        }

        $given = [];

        foreach ($message[self::ANSWERS] as $answer) {
            if (is_array($answer) && ($id = self::string($answer['id'] ?? '')) !== '') {
                $text = self::string($answer['answer'] ?? '');
                $given[$id] = $text !== '' ? $text : null;
            }
        }

        return $given;
    }

    /**
     * @param  array<int, string>  $ids  The ids already taken.
     */
    private static function question(mixed $item, int $number, array $ids): ?AskedQuestion
    {
        if (is_string($item)) {
            $item = ['question' => $item];
        }

        if (! is_array($item)) {
            return null;
        }

        $text = self::string($item['question'] ?? $item['text'] ?? '');

        if ($text === '') {
            return null;
        }

        $id = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', self::string($item['id'] ?? '')));
        $id = trim($id, '-');

        if ($id === '' || in_array($id, $ids, true)) {
            $id = 'q'.$number;
        }

        $options = self::options($item['options'] ?? []);
        $choice = strtolower(self::string($item['kind'] ?? '')) === AskedQuestion::CHOICE && count($options) >= 2;

        return new AskedQuestion(
            $id,
            $text,
            self::string($item['hint'] ?? ''),
            $choice ? AskedQuestion::CHOICE : AskedQuestion::TEXT,
            $choice ? $options : [],
            filter_var($item['optional'] ?? false, FILTER_VALIDATE_BOOLEAN),
        );
    }

    /**
     * @return list<string>
     */
    private static function options(mixed $options): array
    {
        if (! is_array($options)) {
            return [];
        }

        $kept = [];

        foreach ($options as $option) {
            $option = self::string($option);

            if ($option !== '' && ! in_array($option, $kept, true)) {
                $kept[] = $option;
            }
        }

        return array_slice($kept, 0, self::MAX_OPTIONS);
    }

    private static function string(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * A model sometimes wraps YAML in a code fence.
     */
    private static function unfenced(string $block): string
    {
        return (string) preg_replace('/^\s*```[a-z]*\s*\n|\n\s*```\s*$/i', '', trim($block));
    }
}
