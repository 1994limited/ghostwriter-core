<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\OAuth;

use DateTimeImmutable;

/**
 * A library's tokens: a client-credentials token cached until it expires
 * (Getty 30 minutes, Alamy 24 hours), or a user's tokens with a refresh
 * token. Kept per site through LibraryTokens, encrypted at rest by the
 * addon. Never logged: var_dump() and print_r() show it masked.
 */
final class TokenSet
{
    /**
     * @param  array<int, string>  $scopes
     */
    public function __construct(
        public readonly string $accessToken,
        public readonly ?DateTimeImmutable $expiresAt = null,
        public readonly ?string $refreshToken = null,
        public readonly array $scopes = [],
        public readonly string $type = 'Bearer',
    ) {}

    /**
     * From a token endpoint's answer: `access_token`, `expires_in`
     * (seconds), `refresh_token`, `scope`, `token_type`. Null when it holds
     * no token.
     *
     * @param  array<mixed>  $answer
     */
    public static function fromResponse(array $answer, ?DateTimeImmutable $now = null): ?self
    {
        if (! is_string($answer['access_token'] ?? null) || $answer['access_token'] === '') {
            return null;
        }

        $expiresIn = $answer['expires_in'] ?? null;

        return new self(
            $answer['access_token'],
            is_numeric($expiresIn) ? ($now ?? new DateTimeImmutable)->setTimestamp(($now ?? new DateTimeImmutable)->getTimestamp() + (int) $expiresIn) : null,
            is_string($answer['refresh_token'] ?? null) && $answer['refresh_token'] !== '' ? $answer['refresh_token'] : null,
            is_string($answer['scope'] ?? null) ? array_values(array_filter(explode(' ', $answer['scope']))) : [],
            is_string($answer['token_type'] ?? null) ? $answer['token_type'] : 'Bearer',
        );
    }

    /**
     * Expired, or about to: within $leeway seconds of it.
     */
    public function isExpired(?DateTimeImmutable $now = null, int $leeway = 60): bool
    {
        return $this->expiresAt !== null && ($now ?? new DateTimeImmutable)->getTimestamp() >= $this->expiresAt->getTimestamp() - $leeway;
    }

    public function canRefresh(): bool
    {
        return $this->refreshToken !== null;
    }

    /** The Authorization header's value. */
    public function header(): string
    {
        return $this->type.' '.$this->accessToken;
    }

    /**
     * For the addon to encrypt and keep.
     *
     * @return array{access_token: string, expires_at: ?string, refresh_token: ?string, scopes: array<int, string>, type: string}
     */
    public function toArray(): array
    {
        return [
            'access_token' => $this->accessToken,
            'expires_at' => $this->expiresAt?->format(DATE_ATOM),
            'refresh_token' => $this->refreshToken,
            'scopes' => $this->scopes,
            'type' => $this->type,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        if (! is_string($data['access_token'] ?? null) || $data['access_token'] === '') {
            return null;
        }

        try {
            $expiresAt = is_string($data['expires_at'] ?? null) ? new DateTimeImmutable($data['expires_at']) : null;
        } catch (\Exception) {
            $expiresAt = null;
        }

        return new self(
            $data['access_token'],
            $expiresAt,
            is_string($data['refresh_token'] ?? null) ? $data['refresh_token'] : null,
            is_array($data['scopes'] ?? null) ? array_values(array_filter($data['scopes'], 'is_string')) : [],
            is_string($data['type'] ?? null) ? $data['type'] : 'Bearer',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['accessToken' => '***', 'expiresAt' => $this->expiresAt, 'refreshToken' => $this->refreshToken === null ? null : '***', 'scopes' => $this->scopes, 'type' => $this->type];
    }
}
