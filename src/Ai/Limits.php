<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai;

/**
 * How much image data one request may carry. Every provider refuses a
 * request over these before anything is sent (BadResponse), so addons can
 * size their requests against them: the photo picker, for instance, sends
 * 3 references and up to 18 thumbnails, 21 images in all.
 *
 * - MAX_IMAGES is well inside every provider's own cap (Anthropic takes 100
 *   images a request, OpenAI and Gemini more).
 * - MAX_IMAGE_BYTES counts the raw bytes. Base64 adds a third on the wire,
 *   so 20 MB of images is about 27 MB of request, under Anthropic's 32 MB
 *   request limit. Callers shrink images first; this is a guard.
 */
final class Limits
{
    /** The most images one request may carry. */
    public const MAX_IMAGES = 24;

    /** The most image data one request may carry, in bytes (raw, before base64). */
    public const MAX_IMAGE_BYTES = 20 * 1024 * 1024;

    /**
     * Whether the images fit in one request.
     *
     * @param  array<int, Image>  $images
     */
    public static function fits(array $images): bool
    {
        return count($images) <= self::MAX_IMAGES && self::bytes($images) <= self::MAX_IMAGE_BYTES;
    }

    /**
     * @param  array<int, Image>  $images
     */
    public static function bytes(array $images): int
    {
        return array_sum(array_map(fn (Image $image) => $image->bytes(), $images));
    }
}
