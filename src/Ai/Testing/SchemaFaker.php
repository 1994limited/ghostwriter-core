<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai\Testing;

use NineteenNinetyFour\Ghostwriter\Core\Ai\OutputSchema;

/**
 * Made-up data of a schema's shape, for tests that need a structured reply
 * and don't care what it says (FakeProvider::respondFromSchema()). The same
 * schema always gives the same data: the first enum value, `text` for a
 * string, 0, false, one item per list (or `minItems`), every property.
 */
final class SchemaFaker
{
    /**
     * @return array<string, mixed>
     */
    public static function fake(OutputSchema $schema): array
    {
        $data = self::value($schema->schema, $schema->schema, 0);

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $root
     */
    public static function value(array $node, array $root, int $depth): mixed
    {
        if ($depth > 8) {
            return null;
        }

        if (is_string($node['$ref'] ?? null) && str_starts_with($node['$ref'], '#/')) {
            $target = $root;

            foreach (explode('/', substr($node['$ref'], 2)) as $segment) {
                $target = is_array($target) ? ($target[$segment] ?? null) : null;
            }

            return is_array($target) ? self::value($target, $root, $depth + 1) : null;
        }

        if (array_key_exists('const', $node)) {
            return $node['const'];
        }

        if (is_array($node['enum'] ?? null) && $node['enum'] !== []) {
            return array_values($node['enum'])[0];
        }

        foreach (['anyOf', 'oneOf'] as $key) {
            if (is_array($node[$key] ?? null)) {
                foreach ($node[$key] as $branch) {
                    if (is_array($branch) && ($branch['type'] ?? null) !== 'null') {
                        return self::value($branch, $root, $depth + 1);
                    }
                }

                return null;
            }
        }

        $type = $node['type'] ?? (isset($node['properties']) ? 'object' : (isset($node['items']) ? 'array' : 'string'));
        $type = is_array($type) ? (array_values(array_filter($type, fn ($t) => $t !== 'null'))[0] ?? 'null') : $type;

        return match ($type) {
            'object' => self::object($node, $root, $depth),
            'array' => array_fill(0, max(1, is_int($node['minItems'] ?? null) ? $node['minItems'] : 1), is_array($node['items'] ?? null) ? self::value($node['items'], $root, $depth + 1) : 'text'),
            'integer' => is_int($node['minimum'] ?? null) ? $node['minimum'] : 0,
            'number' => is_numeric($node['minimum'] ?? null) ? $node['minimum'] + 0 : 0,
            'boolean' => false,
            'null' => null,
            default => 'text',
        };
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $root
     * @return array<string, mixed>
     */
    private static function object(array $node, array $root, int $depth): array
    {
        $object = [];

        foreach (is_array($node['properties'] ?? null) ? $node['properties'] : [] as $name => $child) {
            $object[(string) $name] = is_array($child) ? self::value($child, $root, $depth + 1) : null;
        }

        return $object;
    }
}
