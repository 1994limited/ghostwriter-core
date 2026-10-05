<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoField;

/**
 * SeoWriter for SEO values kept as plain text (Gaps\PlainSeoFields:
 * `seo_title`, `meta_description`…), and for any SEO field whose text is
 * a plain string at its path: the text goes there, as it is.
 */
final class PlainSeoWriter implements SeoWriter
{
    public function write(array $values, SeoField $field, string $text): array
    {
        $segments = array_map(fn ($segment) => is_string($segment) ? $segment : (string) $segment->index, $field->path->segments);
        $at = &$values;

        foreach ($segments as $i => $segment) {
            if ($i === count($segments) - 1) {
                $at[$segment] = $text;

                break;
            }

            if (! is_array($at[$segment] ?? null)) {
                $at[$segment] = [];
            }

            $at = &$at[$segment];
        }

        unset($at);

        return $values;
    }
}
