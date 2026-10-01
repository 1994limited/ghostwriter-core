<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai\Providers;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\BadResponse;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\NotConfigured;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Http\BaseUrl;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Http\Transport;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Image;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Limits;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Models;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextResponse;

/**
 * What the three providers share: a key, a transport, the defaults the
 * settings chose, and the checks made before anything is sent.
 */
abstract class HttpProvider
{
    /** @deprecated Use Limits::MAX_IMAGES. */
    public const MAX_IMAGES = Limits::MAX_IMAGES;

    /** @deprecated Use Limits::MAX_IMAGE_BYTES. */
    public const MAX_IMAGE_BYTES = Limits::MAX_IMAGE_BYTES;

    protected readonly ?string $baseUrl;

    /**
     * @param  string|null  $model  The text model chosen in the settings; null for Models' default.
     * @param  string|null  $imageModel  The image model chosen in the settings; null for Models' default.
     * @param  string|null  $baseUrl  A gateway that speaks this provider's API; null for the provider's own.
     * @param  int  $timeout  Seconds per call, unless a request sets its own.
     *
     * @throws NotConfigured for a base URL core won't send a key to.
     */
    public function __construct(
        protected readonly string $apiKey,
        protected readonly Transport $transport,
        protected readonly ?string $model = null,
        protected readonly ?string $imageModel = null,
        ?string $baseUrl = null,
        protected readonly int $timeout = 300,
    ) {
        $this->baseUrl = BaseUrl::check($this->handle(), $baseUrl);
    }

    abstract public function handle(): string;

    protected function textModel(TextRequest $request): string
    {
        return ($request->model ?: $this->model) ?: Models::defaultText($this->handle());
    }

    protected function imageModelFor(?string $model): string
    {
        return ($model ?: $this->imageModel) ?: Models::defaultImage($this->handle());
    }

    protected function timeoutFor(?int $timeout): int
    {
        return $timeout ?? $this->timeout;
    }

    /**
     * Providers cap the size of a request, and the photo picker sends many
     * images. Callers already shrink them; this is a guard (see Limits).
     *
     * @param  array<int, Image>  $images
     *
     * @throws BadResponse before anything is sent, when there are too many or they are too large.
     */
    protected function guard(string $agent, array $images): void
    {
        $bytes = Limits::bytes($images);

        if (count($images) > Limits::MAX_IMAGES) {
            throw new BadResponse(sprintf('The %s request has %d images; at most %d can be sent at once.', $agent ?: 'model', count($images), Limits::MAX_IMAGES), $this->handle());
        }

        if ($bytes > Limits::MAX_IMAGE_BYTES) {
            throw new BadResponse(sprintf('The %s request has %.1f MB of images; at most %d MB can be sent at once.', $agent ?: 'model', $bytes / 1048576, Limits::MAX_IMAGE_BYTES / 1048576), $this->handle());
        }
    }

    protected function finished(string $agent, TextResponse $response, float $started): TextResponse
    {
        $this->transport->logger()->info('A model call finished.', [
            'agent' => $agent,
            'provider' => $this->handle(),
            'model' => $response->model,
            'input_tokens' => $response->usage->input,
            'output_tokens' => $response->usage->output,
            'stop_reason' => $response->stopReason->value,
            'attempts' => $this->transport->attempts(),
            'ms' => (int) round((microtime(true) - $started) * 1000),
        ]);

        return $response;
    }

    /**
     * An image the provider sent back as base64.
     *
     * @throws BadResponse when it isn't one.
     */
    protected function decodedImage(string $encoded): Image
    {
        try {
            return Image::fromString((string) base64_decode($encoded, true));
        } catch (\InvalidArgumentException $exception) {
            throw new BadResponse("{$this->transport->label()} sent back an image that could not be read.", $this->handle(), null, $exception);
        }
    }
}
