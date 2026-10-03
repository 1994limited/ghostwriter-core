<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Studio;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Message;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\BriefThread;

/**
 * A writing session as the writer sees it: the messages so far (the last is
 * the person's latest; on the first turn, the brief), the current draft and
 * the questionnaire answers. A session's messages can be passed as they
 * are: the brief's steps before it was agreed (BriefThread) are left out.
 */
final class Conversation
{
    /** @var array<int, Message> */
    public readonly array $messages;

    /**
     * @param  array<int, Message|array<string, mixed>>  $messages  Messages, or arrays with `role` and `content` (other keys are ignored).
     * @param  array<string, mixed>  $answers  Answers by question handle.
     */
    public function __construct(
        array $messages,
        public readonly ?string $draft = null,
        public readonly array $answers = [],
    ) {
        // The brief's own steps before it was agreed (the quick details, the
        // brief card) aren't the writer's: it starts from the agreed brief.
        $this->messages = Studio::messages(array_values(array_filter(
            $messages,
            fn (Message|array $message) => ! is_array($message) || BriefThread::forWriter($message),
        )));
    }
}
