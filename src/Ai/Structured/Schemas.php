<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai\Structured;

use NineteenNinetyFour\Ghostwriter\Core\Ai\OutputSchema;

/**
 * An OutputSchema rewritten into the subset of JSON Schema each provider's
 * structured output accepts. No model, no network: the same schema always
 * gives the same bytes, so a cached prompt stays cached.
 *
 * Every rewrite closes every object (`additionalProperties: false`) and
 * keeps only the keywords the provider takes. A constraint it doesn't take
 * (a length, a pattern, a count) is folded into the node's `description`,
 * so the model is still told; the caller checks the value anyway.
 *
 * - anthropic(): Claude's `output_config.format` and strict tools. Optional
 *   properties stay optional (Claude allows 24 across a request, and 16
 *   unions), `minItems` only 0 or 1, string formats from its list.
 * - strict(): OpenAI's `response_format` with `strict: true`, also sent
 *   through OpenRouter. Every property is required; an optional one is made
 *   nullable instead (a `null` type, enum value or anyOf branch).
 * - gemini(): Gemini's `responseJsonSchema`. Optional properties stay
 *   optional; `const` becomes a one-value `enum`.
 */
final class Schemas
{
    /** String formats every provider here accepts. */
    public const FORMATS = ['date-time', 'time', 'date', 'duration', 'email', 'hostname', 'uri', 'ipv4', 'ipv6', 'uuid'];

    private const ANTHROPIC = ['type', 'properties', 'required', 'additionalProperties', 'items', 'enum', 'const', 'anyOf', 'allOf', 'description', '$ref', '$defs', 'definitions', 'default', 'format', 'minItems'];

    private const STRICT = ['type', 'properties', 'required', 'additionalProperties', 'items', 'enum', 'const', 'anyOf', 'description', '$ref', '$defs', 'format'];

    private const GEMINI = ['type', 'properties', 'required', 'additionalProperties', 'items', 'enum', 'anyOf', 'description', '$ref', '$defs', 'format', 'minItems', 'maxItems', 'minimum', 'maximum'];

    /** @return array<string, mixed> */
    public static function anthropic(OutputSchema $schema): array
    {
        return self::node($schema->toArray(), self::ANTHROPIC, false);
    }

    /** @return array<string, mixed> */
    public static function strict(OutputSchema $schema): array
    {
        return self::node($schema->toArray(), self::STRICT, true);
    }

    /** @return array<string, mixed> */
    public static function gemini(OutputSchema $schema): array
    {
        return self::node($schema->toArray(), self::GEMINI, false);
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<string>  $keep
     * @return array<string, mixed>
     */
    private static function node(array $node, array $keep, bool $strict): array
    {
        $notes = [];

        if (isset($node['oneOf']) && is_array($node['oneOf'])) {
            $node['anyOf'] = [...(is_array($node['anyOf'] ?? null) ? $node['anyOf'] : []), ...$node['oneOf']];
            unset($node['oneOf']);
        }

        if (array_key_exists('const', $node) && ! in_array('const', $keep, true)) {
            $node['enum'] = [$node['const']];
            unset($node['const']);
        }

        if (isset($node['format']) && ! in_array($node['format'], self::FORMATS, true)) {
            $notes[] = 'Format: '.self::scalar($node['format']).'.';
            unset($node['format']);
        }

        if (isset($node['enum']) && (! is_array($node['enum']) || array_filter($node['enum'], fn ($value) => ! is_scalar($value) && $value !== null) !== [])) {
            $notes[] = 'One of: '.json_encode($node['enum']).'.';
            unset($node['enum']);
        }

        // Claude takes minItems of 0 or 1 only.
        if (in_array('minItems', $keep, true) && ! in_array('maxItems', $keep, true) && is_int($node['minItems'] ?? null) && $node['minItems'] > 1) {
            $notes[] = "At least {$node['minItems']} items.";
            $node['minItems'] = 1;
        }

        foreach (array_keys($node) as $key) {
            if (in_array($key, $keep, true)) {
                continue;
            }

            if (($note = self::note((string) $key, $node[$key])) !== null) {
                $notes[] = $note;
            }

            unset($node[$key]);
        }

        if (self::isObject($node)) {
            $node['additionalProperties'] = false;
        }

        foreach (['$defs', 'definitions'] as $key) {
            if (is_array($node[$key] ?? null)) {
                $node[$key] = array_map(fn ($child) => is_array($child) ? self::node($child, $keep, $strict) : $child, $node[$key]);
            }
        }

        foreach (['anyOf', 'allOf'] as $key) {
            if (is_array($node[$key] ?? null)) {
                $node[$key] = array_values(array_map(fn ($child) => is_array($child) ? self::node($child, $keep, $strict) : $child, $node[$key]));
            }
        }

        if (is_array($node['items'] ?? null) && ! array_is_list($node['items'])) {
            $node['items'] = self::node($node['items'], $keep, $strict);
        }

        if (is_array($node['properties'] ?? null)) {
            $required = is_array($node['required'] ?? null) ? array_values(array_filter($node['required'], fn ($name) => is_string($name) && isset($node['properties'][$name]))) : [];
            $properties = [];

            foreach ($node['properties'] as $name => $child) {
                $child = is_array($child) ? self::node($child, $keep, $strict) : [];

                if ($strict && ! in_array($name, $required, true)) {
                    $child = self::nullable($child);
                }

                $properties[$name] = $child;
            }

            $node['properties'] = $properties;
            $node['required'] = $strict ? array_map('strval', array_keys($properties)) : $required;
        }

        return self::describe($node, $notes);
    }

    /**
     * An optional property made nullable, for strict mode, where every
     * property must be present.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private static function nullable(array $node): array
    {
        if (isset($node['anyOf']) && is_array($node['anyOf'])) {
            if (! in_array(['type' => 'null'], $node['anyOf'], true)) {
                $node['anyOf'][] = ['type' => 'null'];
            }

            return $node;
        }

        if (isset($node['$ref'])) {
            $description = $node['description'] ?? null;
            unset($node['description']);

            return array_filter(['anyOf' => [$node, ['type' => 'null']], 'description' => $description], fn ($value) => $value !== null);
        }

        if (isset($node['type'])) {
            $types = is_array($node['type']) ? $node['type'] : [$node['type']];
            $node['type'] = in_array('null', $types, true) ? $types : [...$types, 'null'];
        }

        if (isset($node['enum']) && is_array($node['enum']) && ! in_array(null, $node['enum'], true)) {
            $node['enum'][] = null;
        }

        return $node;
    }

    /** @param array<string, mixed> $node */
    private static function isObject(array $node): bool
    {
        $type = $node['type'] ?? null;

        return $type === 'object' || (is_array($type) && in_array('object', $type, true)) || (! isset($type) && isset($node['properties']));
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<string>  $notes
     * @return array<string, mixed>
     */
    private static function describe(array $node, array $notes): array
    {
        if ($notes === []) {
            return $node;
        }

        $description = trim(is_string($node['description'] ?? null) ? $node['description'] : '');

        if ($description !== '' && ! preg_match('/[.!?:;]$/u', $description)) {
            $description .= '.';
        }

        $node['description'] = trim($description.' '.implode(' ', $notes));

        return $node;
    }

    /** A constraint a provider doesn't take, in words, or null for one that only documents. */
    private static function note(string $keyword, mixed $value): ?string
    {
        $value = self::scalar($value);

        return match ($keyword) {
            'minimum' => "At least {$value}.",
            'exclusiveMinimum' => "More than {$value}.",
            'maximum' => "At most {$value}.",
            'exclusiveMaximum' => "Less than {$value}.",
            'multipleOf' => "A multiple of {$value}.",
            'minLength' => "At least {$value} characters.",
            'maxLength' => "At most {$value} characters.",
            'pattern' => "Matches {$value}.",
            'minItems' => "At least {$value} items.",
            'maxItems' => "At most {$value} items.",
            'uniqueItems' => $value === 'true' ? 'No item twice.' : null,
            default => null,
        };
    }

    private static function scalar(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value),
        };
    }
}
