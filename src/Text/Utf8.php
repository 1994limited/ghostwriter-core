<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Text;

/**
 * Text that has been through a model, a photo library or a site's database
 * is not always valid UTF-8 all the way through, and one bad byte makes
 * json_encode give up on the whole value. Anything written as JSON goes
 * through here first.
 */
final class Utf8
{
    /**
     * Every string in the value, made valid UTF-8. Arrays are walked; other
     * values come back as they were.
     */
    public static function scrub(mixed $value): mixed
    {
        if (is_string($value)) {
            return mb_check_encoding($value, 'UTF-8') ? $value : mb_scrub($value, 'UTF-8');
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::scrub($item);
            }
        }

        return $value;
    }
}
