<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts;

use NineteenNinetyFour\Ghostwriter\Core\Connections\CredentialStore;

/**
 * What every CredentialStore must do: keep each name's value whole, forget
 * it, keep names apart, and **never keep a value as plain text**. The
 * addon's test says what is at rest for a name (contractRaw()): a
 * database column, a file's text.
 */
trait CredentialStoreContract
{
    abstract protected function contractStore(): CredentialStore;

    /**
     * Everything kept at rest for a name, as stored (not decrypted), or
     * null when nothing is.
     */
    abstract protected function contractRaw(string $name): ?string;

    /** Whether contractRaw() can see the at-rest value (false for an in-memory store). */
    protected function contractEncrypts(): bool
    {
        return true;
    }

    public function test_a_kept_value_comes_back_whole(): void
    {
        $store = $this->contractStore();
        $value = ['fields' => ['key' => 'sk-test-0123456789abcdef', 'secret' => 'shh-ünïcode-✓'], 'saved_at' => '2026-10-05T10:00:00+00:00', 'via' => 'paste'];

        $store->put('pexels', $value);

        $this->assertSame($value, $store->get('pexels'));
    }

    public function test_nothing_kept_reads_as_null(): void
    {
        $this->assertNull($this->contractStore()->get('unsplash'));
    }

    public function test_putting_again_replaces_the_value(): void
    {
        $store = $this->contractStore();

        $store->put('pexels', ['fields' => ['key' => 'first-key-0123456789']]);
        $store->put('pexels', ['fields' => ['key' => 'second-key-0123456789']]);

        $this->assertSame(['fields' => ['key' => 'second-key-0123456789']], $store->get('pexels'));
    }

    public function test_forgetting_removes_only_that_name(): void
    {
        $store = $this->contractStore();

        $store->put('pexels', ['fields' => ['key' => 'pexels-key-0123456789']]);
        $store->put('health:pexels', ['fingerprint' => 'abc']);
        $store->put('tokens:shutterstock', ['access_token' => 'token-0123456789']);
        $store->forget('pexels');
        $store->forget('never-kept');

        $this->assertNull($store->get('pexels'));
        $this->assertSame(['fingerprint' => 'abc'], $store->get('health:pexels'));
        $this->assertSame(['access_token' => 'token-0123456789'], $store->get('tokens:shutterstock'));
        $this->assertNull($this->contractRaw('pexels'));
    }

    public function test_a_value_is_never_kept_as_plain_text(): void
    {
        if (! $this->contractEncrypts()) {
            $this->markTestSkipped('This store keeps nothing at rest.');
        }

        $store = $this->contractStore();
        $secret = 'sk-very-secret-0123456789abcdef';

        $store->put('anthropic', ['fields' => ['key' => $secret]]);
        $raw = (string) $this->contractRaw('anthropic');

        $this->assertNotSame('', $raw, 'Something is kept at rest.');
        $this->assertStringNotContainsString($secret, $raw);
        $this->assertStringNotContainsString(substr($secret, 0, 12), $raw);
        $this->assertStringNotContainsString(base64_encode($secret), $raw);
        $this->assertStringNotContainsString('"fields"', $raw);
        $this->assertSame(['fields' => ['key' => $secret]], $store->get('anthropic'));
    }
}
