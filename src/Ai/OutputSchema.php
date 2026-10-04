<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai;

use InvalidArgumentException;

/**
 * The shape a reply must take, as a JSON Schema object, sent with a
 * TextRequest so the provider can hold the model to it (structured output).
 *
 * The schema is plain JSON Schema as core writes it: an object at the root,
 * `required` naming what is always there and leaving out what is optional,
 * `description` on anything the model should be told about. Each provider
 * rewrites it into the subset it accepts before sending (Structured\*):
 * strict providers get every property required with optional ones made
 * nullable, constraints a provider rejects are folded into the description.
 * Write properties in the order the model should fill them in: models write
 * them in schema order, so a short notes field first is thought before the
 * answer.
 *
 * A schema guarantees shape, not truth: callers still check every value.
 *
 *     $schema = OutputSchema::fromFile('verdicts', __DIR__.'/verdicts.json');
 *     $request = new TextRequest('verifier', $instructions, $prompt, schema: $schema);
 */
final class OutputSchema
{
    /**
     * @param  string  $name  Letters, digits, `_` and `-` only, as OpenAI asks; used to name the schema to providers that want a name.
     * @param  array<string, mixed>  $schema  A JSON Schema whose root is an object.
     *
     * @throws InvalidArgumentException for a bad name or a root that isn't an object.
     */
    public function __construct(
        public readonly string $name,
        public readonly array $schema,
        public readonly string $description = '',
    ) {
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $name) !== 1) {
            throw new InvalidArgumentException("\"{$name}\" can't name an output schema: use letters, digits, _ and - only.");
        }

        if (($schema['type'] ?? null) !== 'object' || ! is_array($schema['properties'] ?? null)) {
            throw new InvalidArgumentException("The {$name} output schema must be an object with properties at its root.");
        }
    }

    /**
     * A schema from a JSON file; its `description` becomes the schema's.
     *
     * @throws InvalidArgumentException when the file can't be read or isn't a schema.
     */
    public static function fromFile(string $name, string $path): self
    {
        $schema = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (! is_array($schema)) {
            throw new InvalidArgumentException("The {$name} output schema at {$path} could not be read.");
        }

        return new self($name, $schema, is_string($schema['description'] ?? null) ? $schema['description'] : '');
    }

    /**
     * The schema without the keys that only document it (`$schema`, `$id`,
     * `$comment`, `title` at the root), as every provider gets it before
     * its own rewriting.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $schema = $this->schema;
        unset($schema['$schema'], $schema['$id'], $schema['$comment'], $schema['title']);

        return $schema;
    }
}
