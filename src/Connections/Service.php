<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Connections;

/**
 * An outside service Ghostwriter uses, as the Connections page shows it:
 * its name, its group (Writing, Images, Stock photos), the page where its
 * key is made, the fields to paste, and how to sign in instead where it
 * can. The words (what it is, the steps) are in resources/lang/{en,…}/connections.php
 * under `<id>.about` and `<id>.step.1`…, read through Strings.
 */
final class Service
{
    public const WRITING = 'writing';

    public const IMAGES = 'images';

    public const STOCK = 'stock';

    /** Only offered to the end-to-end tests (Services::withTestServices()). */
    public const TEST = 'test';

    public const GROUPS = [self::WRITING, self::IMAGES, self::STOCK, self::TEST];

    /** "Connect with OpenRouter": OAuth that ends in a key (Ai\Credentials\ConnectsProvider). */
    public const OAUTH_KEY = 'key';

    /** A paid library's "Connect account", needed to license (Images\Libraries\ConnectsAccount). */
    public const OAUTH_ACCOUNT = 'account';

    /**
     * @param  array<int, Field>  $fields  None for a service that needs no key (Openverse).
     * @param  int  $steps  How many plain steps it has in the strings (`<id>.step.1`…).
     * @param  string|null  $oauth  OAUTH_KEY or OAUTH_ACCOUNT, where the card also signs in.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $group,
        public readonly ?string $keyUrl,
        public readonly array $fields,
        public readonly int $steps = 3,
        public readonly ?string $oauth = null,
        public readonly bool $makesImages = false,
    ) {}

    public function needsKey(): bool
    {
        return $this->fields !== [];
    }

    public function field(string $name): ?Field
    {
        foreach ($this->fields as $field) {
            if ($field->name === $name) {
                return $field;
            }
        }

        return null;
    }

    /**
     * The fields that must be pasted.
     *
     * @return array<int, Field>
     */
    public function required(): array
    {
        return array_values(array_filter($this->fields, fn (Field $field) => ! $field->optional));
    }

    /**
     * The environment variables that set it, by field.
     *
     * @return array<string, string>
     */
    public function env(): array
    {
        $env = [];

        foreach ($this->fields as $field) {
            $env[$field->name] = $field->env;
        }

        return $env;
    }

    /**
     * For a page: everything but the words, which Strings fills in.
     *
     * @return array{id: string, name: string, group: string, key_url: ?string, needs_key: bool, oauth: ?string, makes_images: bool, about: string, steps: array<int, string>, fields: array<int, array{name: string, label: string, env: string, optional: bool}>}
     */
    public function toArray(?Strings $strings = null): array
    {
        $strings ??= Strings::english();
        $steps = [];

        for ($i = 1; $i <= $this->steps; $i++) {
            $steps[] = $strings->get("{$this->id}.step.{$i}", ['service' => $this->name]);
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'group' => $this->group,
            'key_url' => $this->keyUrl,
            'needs_key' => $this->needsKey(),
            'oauth' => $this->oauth,
            'makes_images' => $this->makesImages,
            'about' => $strings->get("{$this->id}.about", ['service' => $this->name]),
            'steps' => $steps,
            'fields' => array_map(fn (Field $field) => [
                'name' => $field->name,
                'label' => $strings->has("{$this->id}.field.{$field->name}") ? $strings->get("{$this->id}.field.{$field->name}") : $strings->get("field.{$field->name}"),
                'env' => $field->env,
                'optional' => $field->optional,
            ], $this->fields),
        ];
    }
}
