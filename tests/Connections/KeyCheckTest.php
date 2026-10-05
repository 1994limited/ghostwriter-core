<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Connections;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\ArrayCredentials;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\StaticProviderSettings;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Testing\MockHttpClient;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Testing\NetworkError;
use NineteenNinetyFour\Ghostwriter\Core\Connections\CheckResult;
use NineteenNinetyFour\Ghostwriter\Core\Connections\Connections;
use NineteenNinetyFour\Ghostwriter\Core\Connections\KeyCheck;
use NineteenNinetyFour\Ghostwriter\Core\Connections\KeyWatch;
use NineteenNinetyFour\Ghostwriter\Core\Connections\Services;
use NineteenNinetyFour\Ghostwriter\Core\Connections\Status;
use NineteenNinetyFour\Ghostwriter\Core\Connections\Strings;
use NineteenNinetyFour\Ghostwriter\Core\Connections\Testing\FakeKeyCheck;
use NineteenNinetyFour\Ghostwriter\Core\Connections\Testing\InMemoryCredentialStore;
use PHPUnit\Framework\TestCase;

/**
 * "Check & save" (one cheap live call, tried once) and "Key stopped
 * working", found on use.
 */
class KeyCheckTest extends TestCase
{
    private MockHttpClient $http;

    protected function setUp(): void
    {
        $this->http = new MockHttpClient;
    }

    private function check(string $service, array $fields): CheckResult
    {
        return (new KeyCheck($this->http))->check(Services::all()->get($service), $fields);
    }

    public function test_each_service_is_checked_with_one_cheap_call(): void
    {
        $calls = [
            'anthropic' => ['https://api.anthropic.com/v1/models?limit=1', 'x-api-key', 'sk-ant-0123456789'],
            'openai' => ['https://api.openai.com/v1/models', 'authorization', 'Bearer sk-0123456789'],
            'gemini' => ['https://generativelanguage.googleapis.com/v1beta/models?pageSize=1', 'x-goog-api-key', 'AIza0123456789'],
            'openrouter' => ['https://openrouter.ai/api/v1/key', 'authorization', 'Bearer sk-or-v1-0123456789'],
            'unsplash' => ['https://api.unsplash.com/search/photos?query=garden&per_page=1', 'authorization', 'Client-ID unsplash-0123456789'],
            'pexels' => ['https://api.pexels.com/v1/search?query=garden&per_page=1', 'authorization', 'pexels-0123456789'],
            'pixabay' => ['https://pixabay.com/api/?key=pixabay-0123456789&q=garden&per_page=3', null, null],
            'shutterstock' => ['https://api.shutterstock.com/v2/images/search?query=garden&per_page=1', 'authorization', 'Basic '.base64_encode('ss-key-0123:ss-secret-0123')],
        ];

        foreach ($calls as $service => [$url, $header, $value]) {
            $this->http = new MockHttpClient;
            $this->http->queueJson(['data' => []]);
            $key = $value === null ? 'pixabay-0123456789' : (str_starts_with($value, 'Bearer ') ? substr($value, 7) : (str_starts_with($value, 'Client-ID ') ? substr($value, 10) : $value));
            $fields = $service === 'shutterstock' ? ['key' => 'ss-key-0123', 'secret' => 'ss-secret-0123'] : ['key' => $key];

            $result = $this->check($service, $fields);

            $this->assertTrue($result->ok, $service);
            $request = $this->http->requests[0];
            $this->assertSame('GET', $request->getMethod(), $service);
            $this->assertSame($url, (string) $request->getUri(), $service);

            if ($header !== null) {
                $this->assertSame($value, $request->getHeaderLine($header), $service);
            }
        }
    }

    public function test_a_refused_key_is_said_plainly_and_never_retried(): void
    {
        $this->http->queue($this->http->response(401, '{"error":{"message":"invalid x-api-key"}}'));

        $result = $this->check('anthropic', ['key' => 'sk-ant-wrong']);

        $this->assertFalse($result->ok);
        $this->assertSame('check.refused', $result->reason);
        $this->assertSame('Anthropic didn’t accept that key. Check you copied all of it, with nothing before or after.', $result->message());
        $this->assertSame('Anthropic hat diesen Schlüssel nicht akzeptiert. Prüfen Sie, ob Sie ihn vollständig kopiert haben, ohne etwas davor oder danach.', $result->message(Strings::for('de')));
        $this->assertCount(1, $this->http->requests);
    }

    public function test_pixabay_and_gemini_say_a_bad_key_their_own_way(): void
    {
        $this->http->queue($this->http->response(400, '[ERROR 400] Invalid or missing API key ([key]).'));
        $this->assertSame('check.refused', $this->check('pixabay', ['key' => 'nope'])->reason);

        $this->http->queue($this->http->response(400, '{"error":{"message":"API key not valid. Please pass a valid API key."}}'));
        $this->assertSame('check.refused', $this->check('gemini', ['key' => 'nope'])->reason);
    }

    public function test_busy_unreachable_and_other_answers(): void
    {
        $this->http->queue($this->http->response(429));
        $this->assertSame('check.busy', $this->check('pexels', ['key' => 'pexels-0123456789'])->reason);

        $this->http->queue(NetworkError::connectFailed());
        $this->assertSame('check.unreachable', $this->check('pexels', ['key' => 'pexels-0123456789'])->reason);

        $this->http->queue($this->http->response(500, '{"error":"pexels-0123456789 broke"}'));
        $result = $this->check('pexels', ['key' => 'pexels-0123456789']);
        $this->assertSame('check.failed', $result->reason);
        $this->assertStringNotContainsString('pexels-0123456789', (string) $result->message(), 'The key never reaches the message.');
    }

    public function test_a_missing_field_is_asked_for_without_a_call(): void
    {
        $this->assertSame('Paste the Secret first.', $this->check('shutterstock', ['key' => 'ss-key-0123'])->message());
        $this->assertSame([], $this->http->requests);
    }

    public function test_a_gateway_is_checked_through(): void
    {
        $this->http->queueJson(['data' => []]);

        (new KeyCheck($this->http, new StaticProviderSettings(baseUrls: ['openai' => 'https://gateway.example.com/v1/'])))->check(Services::all()->get('openai'), ['key' => 'sk-0123456789']);

        $this->assertSame('https://gateway.example.com/v1/models', (string) $this->http->requests[0]->getUri());
    }

    public function test_checking_a_new_key_never_marks_the_key_in_use(): void
    {
        $connections = new Connections(new ArrayCredentials, new InMemoryCredentialStore);
        $connections->save('pexels', ['key' => 'pexels-in-use-0123456789']);
        $this->http->queue($this->http->response(401));

        $result = (new KeyCheck(new KeyWatch($this->http, $connections)))->check(Services::all()->get('pexels'), ['key' => 'pexels-wrong-0123456789']);

        $this->assertFalse($result->ok);
        $this->assertSame(Status::CONNECTED, $connections->status('pexels')->state);
    }

    public function test_the_fake_check_calls_nobody(): void
    {
        $fake = new FakeKeyCheck;
        $service = Services::all()->get('pexels');

        $this->assertTrue($fake->check($service, ['key' => 'anything-0123'])->ok);
        $this->assertSame('check.refused', $fake->check($service, ['key' => 'a-wrong-key'])->reason);
        $this->assertSame('check.unreachable', $fake->check($service, ['key' => 'offline'])->reason);
        $this->assertSame(['pexels', 'pexels', 'pexels'], $fake->checked);
    }

    public function test_a_refused_call_marks_the_key_and_an_accepted_one_clears_it(): void
    {
        $store = new InMemoryCredentialStore;
        $connections = new Connections(new ArrayCredentials, $store);
        $connections->save('pexels', ['key' => 'pexels-0123456789']);
        $watch = new KeyWatch($this->http, $connections);
        $get = fn (string $url) => $watch->client(10)->sendRequest($watch->requestFactory()->createRequest('GET', $url));

        $this->http->queue($this->http->response(401), $this->http->response(200, '{}'), $this->http->response(401));

        $this->assertSame(401, $get('https://api.pexels.com/v1/search?query=x')->getStatusCode());
        $this->assertSame(Status::BROKEN, $connections->status('pexels')->state);

        $get('https://api.pexels.com/v1/search?query=y');
        $this->assertSame(Status::CONNECTED, $connections->status('pexels')->state);

        $get('https://images.example.com/photo.jpg');
        $this->assertSame(Status::CONNECTED, $connections->status('pexels')->state, 'Anywhere else is not watched.');
    }

    public function test_a_body_read_to_check_it_is_still_there_for_the_caller(): void
    {
        $connections = new Connections(new ArrayCredentials(['pixabay' => 'pixabay-0123456789']), new InMemoryCredentialStore);
        $watch = new KeyWatch($this->http, $connections);
        $this->http->queue($this->http->response(400, '[ERROR 400] Invalid or missing API key'));

        $response = $watch->client(10)->sendRequest($watch->requestFactory()->createRequest('GET', 'https://pixabay.com/api/?q=x'));

        $this->assertSame('[ERROR 400] Invalid or missing API key', (string) $response->getBody());
        $this->assertTrue($connections->status('pixabay')->broken);
    }
}
