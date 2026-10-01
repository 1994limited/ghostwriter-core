<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Text;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Lays a draft out for reading: each field with its label, markdown rendered,
 * blocks shown in order under their names. The screen draws this tree rather
 * than the raw YAML. Each piece of writing carries its path through the
 * draft, so it can be edited where it is shown.
 *
 * The schema is the shared field-spec shape every addon's schema reader
 * returns: `handle`, `display`, `kind`, and `sets` (blocks) or `fields`
 * (rows, group).
 *
 * The fields of a `group` carry the group's own path. Only one addon's
 * schema reader produces groups today, and that is how it addresses them.
 */
class DraftPreview
{
    private ?MarkdownConverter $converter = null;

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $schema
     * @param  array<int, string|int>  $path  Where this part of the draft sits, so a piece of writing can be edited in place.
     * @return array<int, array<string, mixed>>
     */
    public function render(array $data, array $schema, array $path = []): array
    {
        $nodes = [];

        foreach ($schema as $spec) {
            $handle = (string) $spec['handle'];

            if (! array_key_exists($handle, $data)) {
                continue;
            }

            $value = $data[$handle];

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $here = [...$path, $handle];

            $nodes[] = ['handle' => $handle, 'label' => ($spec['display'] ?? '') ?: $handle, 'path' => $here]
                + $this->node($value, $spec, $here)
                + ['editable' => in_array($spec['kind'], ['text', 'longtext', 'richtext'], true) && is_scalar($value)];
        }

        return $nodes;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<int, string|int>  $path
     * @return array<string, mixed>
     */
    private function node(mixed $value, array $spec, array $path = []): array
    {
        return match ($spec['kind']) {
            'richtext' => ['kind' => 'html', 'html' => $this->html(is_scalar($value) ? (string) $value : ''), 'multiline' => true],
            'blocks' => ['kind' => 'blocks', 'items' => array_values(array_map(function ($block, $i) use ($spec, $path) {
                $type = is_array($block) ? (string) ($block['type'] ?? '') : '';
                $set = $spec['sets'][$type] ?? null;

                return [
                    'type' => $type,
                    'label' => $set['display'] ?? $type,
                    'known' => $set !== null,
                    'fields' => $set ? $this->render((array) $block, $set['fields'], [...$path, $i]) : [],
                ];
            }, array_values((array) $value), array_keys(array_values((array) $value))))],
            'rows' => ['kind' => 'rows', 'items' => array_values(array_map(
                fn ($row, $i) => $this->render((array) $row, $spec['fields'] ?? [], [...$path, $i]),
                array_values((array) $value),
                array_keys(array_values((array) $value)),
            ))],
            'group' => ['kind' => 'group', 'fields' => $this->render((array) $value, $spec['fields'] ?? [], $path)],
            'list', 'choices' => ['kind' => 'list', 'items' => array_values(array_map('strval', array_filter((array) $value, 'is_scalar')))],
            'toggle' => ['kind' => 'text', 'text' => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'Yes' : 'No'],
            'longtext' => ['kind' => 'text', 'text' => is_scalar($value) ? (string) $value : '', 'multiline' => true],
            default => ['kind' => 'text', 'text' => is_scalar($value) ? (string) $value : json_encode($value)],
        };
    }

    /**
     * Raw HTML in the markdown is escaped and unsafe links are dropped: the
     * text came from a model and is shown as HTML.
     */
    private function html(string $markdown): string
    {
        if ($this->converter === null) {
            $environment = new Environment(['html_input' => 'escape', 'allow_unsafe_links' => false]);
            $environment->addExtension(new CommonMarkCoreExtension);
            $environment->addExtension(new TableExtension);

            $this->converter = new MarkdownConverter($environment);
        }

        return (string) $this->converter->convert($markdown);
    }
}
