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
 *
 * With a `schema`, the reply must be JSON of that shape: a provider that
 * can hold the model to it does (structured output, see OutputSchema and
 * Models::structuredOutput()), and puts the decoded object on
 * TextResponse::$structured. One that can't sends the request as it is, and
 * the caller reads the text as before, so the prompt should still describe
 * the shape.
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
        public readonly ?OutputSchema $schema = null,
    ) {
        $this->effort = is_string($effort) ? Effort::tryFrom($effort) : $effort;
    }

    /**
     * A copy with its own token limit, e.g. to retry a cut-off reply with
     * more room.
     */
    public function withMaxTokens(int $maxTokens): self
    {
        return new self($this->agent, $this->instructions, $this->prompt, $this->history, $this->images, $maxTokens, $this->model, $this->timeout, $this->effort, $this->schema);
    }

    /**
     * A copy sent to another model; null goes back to the settings' model.
     */
    public function withModel(?string $model): self
    {
        return new self($this->agent, $this->instructions, $this->prompt, $this->history, $this->images, $this->maxTokens, $model, $this->timeout, $this->effort, $this->schema);
    }

    /**
     * A copy asking for a reply of this shape; null asks for plain text.
     */
    public function withSchema(?OutputSchema $schema): self
    {
        return new self($this->agent, $this->instructions, $this->prompt, $this->history, $this->images, $this->maxTokens, $this->model, $this->timeout, $this->effort, $schema);
    }

    /**
     * A copy with another prompt, the rest kept: e.g. to ask again after a
     * reply that couldn't be read.
     */
    public function withPrompt(string $prompt): self
    {
        return new self($this->agent, $this->instructions, $prompt, $this->history, $this->images, $this->maxTokens, $this->model, $this->timeout, $this->effort, $this->schema);
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
