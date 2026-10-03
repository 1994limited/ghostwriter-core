<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions;

use NineteenNinetyFour\Ghostwriter\Core\Studio\Brief;
use NineteenNinetyFour\Ghostwriter\Core\Studio\BriefRequest;
use NineteenNinetyFour\Ghostwriter\Core\Studio\ContentKind;

/**
 * The brief, in the conversation (since 1.6). Instead of a brief screen,
 * Ghostwriter asks for the quick details, fills in the brief from the
 * reply and shows it as a card to check; agreeing starts the writing.
 *
 * It is all kept in the session's messages, so no store changes: each
 * message of the brief has a `brief` key saying which `step` it is.
 *
 * | step        | role      | content                          | also under `brief`                         |
 * |-------------|-----------|----------------------------------|--------------------------------------------|
 * | `ask`       | assistant | ASK                              |                                            |
 * | `details`   | user      | the person's reply               |                                            |
 * | `idea`      | user      | the idea's title and notes       | `title`, `notes` ("Draft this")            |
 * | `card`      | assistant | CARD (and OPEN)                  | Brief::toArray(), `agreed`                 |
 * | `try-again` | user      | TRY_AGAIN                        | the card's `answers`, `examples`, `title`  |
 * | `agreed`    | user      | the brief, as Studio::brief()    |                                            |
 *
 * The `agreed` message is what the writer starts from, as the brief screen's
 * first message was; Studio\Conversation leaves the others out. The
 * SessionGuard's open(), openFromIdea(), details(), propose(), tryAgain(),
 * agree() and editBrief() write them. See docs/studio.md.
 */
final class BriefThread
{
    /** The key on a message that holds its brief step. */
    public const KEY = 'brief';

    public const ASK = 'ask';

    public const DETAILS = 'details';

    public const IDEA = 'idea';

    public const CARD = 'card';

    public const TRY_AGAIN = 'try-again';

    public const AGREED = 'agreed';

    /** The steps the writer never sees. */
    public const BEFORE_WRITING = [self::ASK, self::DETAILS, self::IDEA, self::CARD, self::TRY_AGAIN];

    /** What Ghostwriter asks first (resources/lang/en/brief.php `ask`). */
    public const ASK_TEXT = 'What’s it called, and what should it say? A line or two is plenty.';

    /** What Ghostwriter says with the card (`card`). */
    public const CARD_TEXT = 'Here’s the brief. Change anything that isn’t right, then start writing.';

    /** Added when the card has something only the person knows (`card.open`). */
    public const OPEN_TEXT = 'Anything in [square brackets] is for you to fill in.';

    /** The person's message when they ask for another brief (`try-again`). */
    public const TRY_AGAIN_TEXT = 'Try again';

    /**
     * The brief step a message is, or null for an ordinary message.
     *
     * @param  array<mixed>  $message
     */
    public static function step(array $message): ?string
    {
        $brief = $message[self::KEY] ?? null;

        return is_array($brief) && is_string($brief['step'] ?? null) ? $brief['step'] : null;
    }

    /**
     * Whether the writer is shown a message: everything but the brief's
     * own steps before it was agreed.
     *
     * @param  array<mixed>  $message
     */
    public static function forWriter(array $message): bool
    {
        return ! in_array(self::step($message), self::BEFORE_WRITING, true);
    }

    /**
     * Where the piece has got to.
     */
    public static function stage(Session $session): BriefStage
    {
        $step = null;

        foreach ($session->messages as $message) {
            $step = self::step($message) ?? $step;
        }

        $last = $session->lastMessage();

        return match ($step) {
            self::ASK => BriefStage::Details,
            self::DETAILS, self::IDEA, self::TRY_AGAIN => BriefStage::Filling,
            self::CARD => BriefStage::Proposed,
            default => match (true) {
                $session->draft !== null => BriefStage::Drafting,
                ! $session->isWorking() && ($last['role'] ?? null) === 'assistant' && ($last['asks'] ?? false) === true => BriefStage::Questions,
                default => BriefStage::Writing,
            },
        };
    }

    /**
     * Whether Ghostwriter's next job for the piece is filling in the brief
     * (rather than a writer's turn): the job a claim or a retry starts.
     */
    public static function fills(Session $session): bool
    {
        return self::stage($session) === BriefStage::Filling;
    }

    /**
     * The brief card as it stands: the latest one, with the person's changes
     * once agreed or edited. Null before there is one, and for pieces
     * started from the old brief screen.
     */
    public static function card(Session $session): ?Brief
    {
        $index = self::cardIndex($session);

        return $index === null ? null : Brief::fromArray((array) $session->messages[$index][self::KEY]);
    }

    /**
     * Whether the brief card has been agreed ("Looks right, start writing").
     */
    public static function agreed(Session $session): bool
    {
        $index = self::cardIndex($session);

        return $index !== null && ($session->messages[$index][self::KEY]['agreed'] ?? false) === true;
    }

    /**
     * The brief the writer started from, as text: the agreed brief, or the
     * first message of a piece started from the old brief screen ("Show the
     * brief" there). Null before it is agreed, and when editing a record.
     */
    public static function text(Session $session): ?string
    {
        foreach ($session->messages as $message) {
            if (self::step($message) === self::AGREED) {
                return is_scalar($message['content'] ?? null) ? (string) $message['content'] : '';
            }
        }

        $first = $session->messages[0] ?? null;

        if (self::cardIndex($session) !== null || $session->isEditing() || ! is_array($first) || ($first['role'] ?? null) !== 'user' || self::step($first) !== null) {
            return null;
        }

        return is_scalar($first['content'] ?? null) ? (string) $first['content'] : '';
    }

    /**
     * The messages to show in the conversation, by their index in
     * `messages`: the ask, the person's reply and the latest brief card
     * (render a message whose step() is `card` as the card, from card(),
     * collapsed to "Show the brief" once agreed), then the conversation.
     * Left out: earlier cards, "Try again", a plan idea's details, and the
     * agreed brief's text (the card shows it). For a piece from the old
     * brief screen the first message, the brief, is left out as before
     * (show text() behind "Show the brief").
     *
     * @return array<int, array<string, mixed>>
     */
    public static function visible(Session $session): array
    {
        $card = self::cardIndex($session);
        $legacy = self::text($session) !== null && $card === null;
        $out = [];

        foreach ($session->messages as $index => $message) {
            $step = self::step($message);

            if (($legacy && $index === 0) || in_array($step, [self::TRY_AGAIN, self::IDEA, self::AGREED], true) || ($step === self::CARD && $index !== $card)) {
                continue;
            }

            $out[$index] = $message;
        }

        return $out;
    }

    /**
     * What to fill the brief from now: the person's details (or the plan
     * idea's), and after "Try again" the card they didn't take with their
     * changes. The examples are the latest card's, or the session's own
     * (chosen when it was opened, as the brief screen chose them).
     *
     * @param  array<int, string>  $titles  Titles of the group's existing records, newest first.
     */
    public static function request(Session $session, ContentKind $kind, array $titles = []): BriefRequest
    {
        $details = [];
        $title = null;
        $card = null;
        $tryAgain = null;

        // Answers the person wrote in an earlier round stay theirs.
        $kept = [];

        foreach ($session->messages as $message) {
            $step = self::step($message);
            $content = is_scalar($message['content'] ?? null) ? trim((string) $message['content']) : '';

            if ($step === self::DETAILS && $content !== '') {
                $details[] = $content;
            } elseif ($step === self::IDEA) {
                $title = is_scalar($message[self::KEY]['title'] ?? null) ? (string) $message[self::KEY]['title'] : null;
                $details[] = is_scalar($message[self::KEY]['notes'] ?? null) ? trim((string) $message[self::KEY]['notes']) : '';
            } elseif ($step === self::CARD) {
                if ($tryAgain !== null) {
                    $kept = $tryAgain->kept;
                }

                $card = Brief::fromArray((array) $message[self::KEY]);
                $tryAgain = null;
            } elseif ($step === self::TRY_AGAIN && $card !== null) {
                $edits = (array) $message[self::KEY];
                $tryAgain = (new BriefRequest($kind, '', kept: $kept))->tryAgain(
                    $card,
                    (array) ($edits['answers'] ?? []),
                    is_array($edits['examples'] ?? null) ? $edits['examples'] : null,
                    is_string($edits['title'] ?? null) ? $edits['title'] : null,
                );
            }
        }

        $request = new BriefRequest($kind, trim(implode("\n\n", array_filter($details))), $title, $titles, $session->examples);
        $last = $session->lastMessage();

        if ($tryAgain !== null && $last !== null && self::step($last) === self::TRY_AGAIN) {
            $request = new BriefRequest($kind, $request->details, $tryAgain->title ?? $title, $titles, $tryAgain->examples, $tryAgain->previous, $tryAgain->kept);
        }

        return $request;
    }

    private static function cardIndex(Session $session): ?int
    {
        $found = null;

        foreach ($session->messages as $index => $message) {
            if (self::step($message) === self::CARD) {
                $found = $index;
            }
        }

        return $found;
    }
}
