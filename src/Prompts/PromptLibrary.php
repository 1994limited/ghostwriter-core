<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Prompts;

use Closure;
use InvalidArgumentException;

/**
 * The prompts every addon sends, by name, with the host's vocabulary filled
 * in.
 *
 * An addon that lets a site override prompts (a published file, a database
 * row) passes `$override`: `fn (string $name): ?string`, returning the
 * override text or null to use core's. Overrides get the vocabulary filled
 * in too.
 *
 * Only `[[name]]` vocabulary placeholders are filled here. The `{{ name }}`
 * placeholders (`{{ count }}`, `{{ voice }}`...) are left for the addon to
 * fill when it knows the values.
 */
final class PromptLibrary
{
    /**
     * Every prompt core ships. The name is also the agent name a request is
     * sent under, apart from `image`, `writer-extras` (a section of the
     * writer's instructions) and `scoped-edit` (the rules every scoped edit
     * shares, a section of the reviewer's and the reworder's).
     */
    public const NAMES = [
        'brief-filler',
        'brief-writer',
        'gap-filler',
        'image',
        'imagery-analyst',
        'kind-finder',
        'layout-planner',
        'photo-picker',
        'photo-query',
        'photo-researcher',
        'planner',
        'reviser',
        'reviewer',
        'reworder',
        'scoped-edit',
        'type-analyst',
        'voice-analyst',
        'voice-editor',
        'writer',
        'writer-extras',
    ];

    /** @var (Closure(string): ?string)|null */
    private readonly ?Closure $override;

    /**
     * @param  (callable(string): ?string)|null  $override
     */
    public function __construct(
        private readonly Vocabulary $vocabulary,
        ?callable $override = null,
    ) {
        $this->override = $override === null ? null : Closure::fromCallable($override);
    }

    /**
     * The prompt, trimmed, with the vocabulary filled in.
     *
     * @throws InvalidArgumentException for a name core has no prompt for and the override doesn't supply.
     */
    public function get(string $name): string
    {
        $text = $this->override !== null ? ($this->override)($name) : null;

        if ($text === null) {
            $text = $this->original($name);
        }

        return strtr(trim($text), $this->vocabulary->terms());
    }

    /**
     * The prompt as core ships it, placeholders and all: the starting point
     * for a site's own override.
     *
     * @throws InvalidArgumentException
     */
    public function original(string $name): string
    {
        $path = self::path($name);

        if (! is_file($path)) {
            throw new InvalidArgumentException("There is no prompt called \"{$name}\".");
        }

        return (string) file_get_contents($path);
    }

    public function vocabulary(): Vocabulary
    {
        return $this->vocabulary;
    }

    /**
     * Where core keeps a prompt's file.
     *
     * @throws InvalidArgumentException for a name that isn't a plain prompt name.
     */
    public static function path(string $name): string
    {
        if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $name)) {
            throw new InvalidArgumentException("There is no prompt called \"{$name}\".");
        }

        return self::directory().'/'.$name.'.md';
    }

    public static function directory(): string
    {
        return dirname(__DIR__, 2).'/resources/prompts';
    }
}
