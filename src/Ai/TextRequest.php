<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai;

/**
 * One call to a model that writes: instructions, the conversation so far,
 * the new prompt and any images to look at.
 *
 * `agent` names the job being done (writer, voice-analyst and so on); it is
 * the prompt's name. Providers use it for its defaults (Agents) and logs;
 * tests use it to fake and inspect one kind of call.
 *
 * Left null, `maxTokens` and `effort` come from Agents, `model` from the
 * settings and then Models, and `timeout` from the settings.
 */
final class TextRequest
{
    public readonly ?Effort $effort;

    /**
     * @param  array<int, Message>  $history  Earlier turns, oldest first.
     * @param  array<int, Image>  $images  Attached to the new prompt.
     * @param  Effort|string|null  $effort  An Effort, or its value ("low"...) as older callers pass it.
     */
    public function __construct(
        public readonly string $agent,
        public readonly string $instructions,
        public readonly string $prompt,
        public readonly array $history = [],
        public readonly array $images = [],
        public readonly ?int $maxTokens = null,
        public readonly ?string $model = null,
        public readonly ?int $timeout = null,
        Effort|string|null $effort = null,
    ) {
        $this->effort = is_string($effort) ? Effort::tryFrom($effort) : $effort;
    }

    /** The token limit to send: the request's own, or the agent's default. */
    public function resolvedMaxTokens(): int
    {
        return $this->maxTokens ?? Agents::maxTokens($this->agent);
    }

    /** The effort to send: the request's own, or the agent's default (null leaves it to the provider). */
    public function resolvedEffort(): ?Effort
    {
        return $this->effort ?? Agents::effort($this->agent);
    }
}
