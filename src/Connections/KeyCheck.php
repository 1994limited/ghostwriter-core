<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Connections;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\AuthenticationFailed;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\NotConfigured;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\Overloaded;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\ProviderException;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\RateLimited;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\Unreachable;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Http\BaseUrl;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Http\RetryPolicy;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Http\Transport;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\HttpClients;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\ProviderSettings;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Providers\Anthropic;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Providers\Gemini;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Providers\OpenAi;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Providers\OpenRouter;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Paid\Shutterstock;
use Psr\Http\Message\ResponseInterface;
use SensitiveParameter;

/**
 * "Check & save": one cheap live call with the pasted key, straight to the
 * service (never through Ghostwriter), tried once:
 *
 * - Anthropic, OpenAI, Gemini: list one model.
 * - OpenRouter: describe the key (GET /key), which a model list doesn't need.
 * - Unsplash, Pexels: one search for one photo; Pixabay three, its least.
 * - Shutterstock: one search with the key and secret (basic auth), on the
 *   sandbox where the site uses it. Searching is free.
 *
 * A gateway set as a provider's base URL is checked through, as calls will be.
 * Nothing is logged: Pixabay takes its key in the address, which a
 * transport error message can repeat.
 */
final class KeyCheck implements ChecksKeys
{
    public const QUERY = 'garden';

    private readonly HttpClients $http;

    /**
     * A KeyWatch is looked through: a pasted key being refused says
     * nothing about the key in use.
     */
    public function __construct(
        HttpClients $http,
        private readonly ?ProviderSettings $settings = null,
        private readonly int $timeout = 20,
        private readonly bool $shutterstockSandbox = false,
    ) {
        $this->http = $http instanceof KeyWatch ? $http->inner() : $http;
    }

    public function check(Service $service, #[SensitiveParameter] array $fields): CheckResult
    {
        foreach ($service->required() as $field) {
            if (trim((string) ($fields[$field->name] ?? '')) === '') {
                return CheckResult::fails('check.missing', ['field' => Strings::english()->get('field.'.$field->name)]);
            }
        }

        $key = trim((string) ($fields[Field::KEY] ?? ''));
        $transport = new Transport($this->http, $service->id, new RetryPolicy(attempts: 1));

        try {
            match ($service->id) {
                'anthropic' => $transport->get($this->base('anthropic', Anthropic::URL).'/v1/models?limit=1', ['x-api-key' => $key, 'anthropic-version' => Anthropic::VERSION], $this->timeout, 'connections'),
                'openai' => $transport->get($this->base('openai', OpenAi::URL).'/models', ['authorization' => "Bearer {$key}"], $this->timeout, 'connections'),
                'gemini' => $transport->withErrors(self::refusedWhen(400, 403))->get($this->base('gemini', Gemini::URL).'/models?pageSize=1', ['x-goog-api-key' => $key], $this->timeout, 'connections'),
                'openrouter' => $transport->get($this->base('openrouter', OpenRouter::URL).'/key', ['authorization' => "Bearer {$key}"], $this->timeout, 'connections'),
                'unsplash' => $transport->get('https://api.unsplash.com/search/photos?'.http_build_query(['query' => self::QUERY, 'per_page' => 1]), ['authorization' => "Client-ID {$key}", 'Accept-Version' => 'v1'], $this->timeout, 'connections'),
                'pexels' => $transport->get('https://api.pexels.com/v1/search?'.http_build_query(['query' => self::QUERY, 'per_page' => 1]), ['authorization' => $key], $this->timeout, 'connections'),
                'pixabay' => $transport->withErrors(self::refusedWhen(400))->get('https://pixabay.com/api/?'.http_build_query(['key' => $key, 'q' => self::QUERY, 'per_page' => 3]), [], $this->timeout, 'connections'),
                'shutterstock' => $transport->get(($this->shutterstockSandbox ? Shutterstock::SANDBOX_API : Shutterstock::API).'/v2/images/search?'.http_build_query(['query' => self::QUERY, 'per_page' => 1]), ['authorization' => 'Basic '.base64_encode($key.':'.trim((string) ($fields[Field::SECRET] ?? '')))], $this->timeout, 'connections'),
                default => throw new NotConfigured('Nothing to check.', $service->id),
            };
        } catch (AuthenticationFailed) {
            return CheckResult::fails('check.refused', ['service' => $service->name]);
        } catch (RateLimited|Overloaded) {
            return CheckResult::fails('check.busy', ['service' => $service->name]);
        } catch (Unreachable) {
            return CheckResult::fails('check.unreachable', ['service' => $service->name]);
        } catch (NotConfigured $exception) {
            return CheckResult::fails('check.failed', ['service' => $service->name, 'reason' => $exception->getMessage()]);
        } catch (ProviderException $exception) {
            return CheckResult::fails('check.failed', ['service' => $service->name, 'reason' => self::reason($exception, $key)]);
        }

        return CheckResult::works();
    }

    private function base(string $provider, string $default): string
    {
        return BaseUrl::check($provider, $this->settings?->baseUrl($provider)) ?? $default;
    }

    /**
     * Services that say a bad key with another status: Pixabay's 400
     * ("Invalid or missing API key"), Gemini's 400 and 403 ("API key not
     * valid"). Only when the body talks about the key.
     *
     * @return callable(ResponseInterface, string): ?ProviderException
     */
    private static function refusedWhen(int ...$statuses): callable
    {
        return fn (ResponseInterface $response, string $body): ?ProviderException => in_array($response->getStatusCode(), $statuses, true) && preg_match('/api[ _-]?key/i', $body)
            ? new AuthenticationFailed('The key was refused.', 'connections', $response->getStatusCode())
            : null;
    }

    private static function reason(ProviderException $exception, #[SensitiveParameter] string $key): string
    {
        $message = $exception->getMessage();

        return $key !== '' ? str_replace($key, '[key]', $message) : $message;
    }
}
