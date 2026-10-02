<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps;

/**
 * What publishing does while gaps that block remain (unlicensed stock
 * previews included): refuse (the default), or go through with a warning.
 * Drafts, working copies and Craft's provisional drafts always save.
 *
 * One setting for both features: `ghostwriter.publish.on_unfinished`
 * (Craft: `onUnfinishedPublish`). It replaces the stock design's
 * `ghostwriter.stock.on_publish`, which is still read when the new one
 * isn't set. There is no per-save "publish anyway".
 */
enum OnPublish: string
{
    case Block = 'block';
    case Warn = 'warn';

    /** The config key, under the addon's `ghostwriter.` prefix. */
    public const CONFIG = 'publish.on_unfinished';

    /** The stock design's key, read when CONFIG isn't set. */
    public const LEGACY_CONFIG = 'stock.on_publish';

    /** Craft's plugin setting. */
    public const CRAFT_SETTING = 'onUnfinishedPublish';

    /**
     * The mode from config: the new key, else the old one, else Block.
     * Anything that isn't `warn` blocks: the safe side.
     */
    public static function fromConfig(mixed $value, mixed $legacy = null): self
    {
        $chosen = is_string($value) && trim($value) !== '' ? $value : $legacy;

        return is_string($chosen) && strtolower(trim($chosen)) === self::Warn->value ? self::Warn : self::Block;
    }
}
