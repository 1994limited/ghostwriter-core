<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Connections;

/**
 * Where a service stands, for its card. Never holds a key: only its last
 * four characters.
 *
 * - CONNECTED: a key set up on the Connections page (or by signing in) is used.
 * - NOT_SET: nothing is set anywhere.
 * - ENV: the environment or config sets it, and wins; `where` says which
 *   ('env' for .env, 'config' for a config file), and the card hides its
 *   set-up and replace buttons.
 * - BROKEN: the key set up here was refused when it was last used.
 * - NO_KEY: the service needs none (Openverse).
 *
 * `broken` is also true for an ENV key that was refused, which the card
 * says beside "Set in .env".
 */
final class Status
{
    public const CONNECTED = 'connected';

    public const NOT_SET = 'not_set';

    public const ENV = 'env';

    public const BROKEN = 'broken';

    public const NO_KEY = 'no_key';

    /**
     * @param  string|null  $where  'env' or 'config', for ENV.
     * @param  string|null  $ending  The key's last four characters, masked ("••a1b2").
     * @param  string|null  $via  How a kept key got here: 'paste', 'connect' or 'migrated'.
     */
    public function __construct(
        public readonly string $service,
        public readonly string $state,
        public readonly ?string $where = null,
        public readonly ?string $ending = null,
        public readonly bool $broken = false,
        public readonly ?string $savedAt = null,
        public readonly ?string $via = null,
    ) {}

    public function usable(): bool
    {
        return in_array($this->state, [self::CONNECTED, self::ENV, self::NO_KEY], true);
    }

    /** The card's one line: "Connected · key ending ••a1b2", "Set in .env"… */
    public function label(?Strings $strings = null): string
    {
        $strings ??= Strings::english();

        return match ($this->state) {
            self::CONNECTED => $strings->get('status.connected', ['ending' => (string) $this->ending]),
            self::ENV => $strings->get($this->where === 'config' ? 'status.config' : 'status.env'),
            self::BROKEN => $strings->get('status.broken'),
            self::NO_KEY => $strings->get('status.no-key'),
            default => $strings->get('status.not-set'),
        };
    }

    /**
     * @return array{service: string, state: string, where: ?string, ending: ?string, broken: bool, saved_at: ?string, via: ?string, label: string}
     */
    public function toArray(?Strings $strings = null): array
    {
        return [
            'service' => $this->service,
            'state' => $this->state,
            'where' => $this->where,
            'ending' => $this->ending,
            'broken' => $this->broken,
            'saved_at' => $this->savedAt,
            'via' => $this->via,
            'label' => $this->label($strings),
        ];
    }
}
