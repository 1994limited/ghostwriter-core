<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai;

/**
 * What a model wrote, why it stopped, what it cost and who answered.
 *
 * `$inputTokens`, `$outputTokens` and `$truncated` are deprecated read-only
 * properties kept for code written against the older response, which had
 * them as fields. They are served by __get() rather than stored, so they can
 * never disagree with `usage` and `stopReason`. Use `$usage->input`,
 * `$usage->output` and `truncated()` instead; they go in 1.0.
 *
 * @property-read int $inputTokens Deprecated: use $usage->input.
 * @property-read int $outputTokens Deprecated: use $usage->output.
 * @property-read bool $truncated Deprecated: use truncated().
 */
final class TextResponse
{
    /** The reply came back held to the request's schema by the provider's JSON output mode. */
    public const JSON_SCHEMA = 'json_schema';

    /** The reply came back as the input of a tool the model was asked to call, standing in for a JSON output mode. */
    public const TOOL = 'tool';

    public readonly Usage $usage;

    /**
     * @param  string  $provider  anthropic, openai, gemini or fake, for logs.
     * @param  string  $model  The model that actually answered, after any fallback.
     * @param  array<string, mixed>|null  $structured  The reply decoded, when the request had a schema and the provider sent it natively; null otherwise, or when it couldn't be decoded.
     * @param  string|null  $structuredBy  How the schema was sent: JSON_SCHEMA, TOOL, or null when it wasn't (no schema, or a model without structured output).
     */
    public function __construct(
        public readonly string $text,
        public readonly StopReason $stopReason = StopReason::End,
        ?Usage $usage = null,
        public readonly string $provider = '',
        public readonly string $model = '',
        public readonly ?array $structured = null,
        public readonly ?string $structuredBy = null,
    ) {
        $this->usage = $usage ?? new Usage;
    }

    /**
     * A copy with other usage, e.g. the sum of a call and its retry.
     */
    public function withUsage(Usage $usage): self
    {
        return new self($this->text, $this->stopReason, $usage, $this->provider, $this->model, $this->structured, $this->structuredBy);
    }

    /** The model stopped at the length limit, not because it had finished. */
    public function truncated(): bool
    {
        return $this->stopReason === StopReason::MaxTokens;
    }

    public function __get(string $name): int|bool
    {
        return match ($name) {
            'inputTokens' => $this->usage->input,
            'outputTokens' => $this->usage->output,
            'truncated' => $this->truncated(),
            default => throw new \LogicException('Undefined property: '.self::class.'::$'.$name),
        };
    }

    public function __isset(string $name): bool
    {
        return in_array($name, ['inputTokens', 'outputTokens', 'truncated'], true);
    }
}
