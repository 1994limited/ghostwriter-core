<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\ProviderException;

/**
 * A model that makes images. Claude does not, so only OpenAI and Gemini
 * implement this.
 */
interface ImageProvider
{
    public function handle(): string;

    /**
     * @throws ProviderException
     */
    public function image(ImageRequest $request): Image;
}
