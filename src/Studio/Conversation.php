<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Studio;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Message;

/**
 * A writing session as the writer sees it: the messages so far (the last is
 * the person's latest; on the first turn, the brief), the current draft and
 * the questionnaire answers.
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
        $this->messages = Studio::messages($messages);
    }
}
