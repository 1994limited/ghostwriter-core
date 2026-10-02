<?php

/**
 * Just enough of a WebSocket client for the DevTools protocol: handshake,
 * masked text frames out, unmasked frames in, with lengths up to 2^63.
 */
class DevToolsSocket
{
    /** @var resource */
    private $socket;

    private int $id = 0;

    /** @var callable|null Called with every event (a message with a method) as it arrives. */
    private $onEvent = null;

    /**
     * Hear events as they arrive, such as screencast frames, even while a
     * command is waiting for its answer.
     */
    public function onEvent(?callable $handler): void
    {
        $this->onEvent = $handler;
    }

    /**
     * Read events for a while, doing nothing else: how a screencast records
     * what the page does on its own, such as an animation settling.
     */
    public function pump(float $seconds): void
    {
        $deadline = microtime(true) + $seconds;

        while (microtime(true) < $deadline) {
            $remaining = $deadline - microtime(true);
            stream_set_timeout($this->socket, (int) max(1, ceil($remaining)));

            try {
                $this->dispatch(json_decode($this->read(), true));
            } catch (RuntimeException $exception) {
                if (! str_contains($exception->getMessage(), 'timed out')) {
                    throw $exception;
                }
            }
        }

        stream_set_timeout($this->socket, 60);
    }

    /**
     * @param  array<string, mixed>|null  $message
     */
    private function dispatch(?array $message): void
    {
        if (isset($message['method']) && $this->onEvent) {
            ($this->onEvent)($message['method'], $message['params'] ?? []);
        }
    }

    public function __construct(string $url)
    {
        $parts = parse_url($url);
        $this->socket = stream_socket_client("tcp://{$parts['host']}:{$parts['port']}", $code, $error, 10)
            ?: throw new RuntimeException("Could not open the DevTools socket: {$error}");

        $key = base64_encode(random_bytes(16));
        fwrite($this->socket, "GET {$parts['path']} HTTP/1.1\r\nHost: {$parts['host']}:{$parts['port']}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\n\r\n");

        $response = '';

        while (! str_contains($response, "\r\n\r\n")) {
            $response .= fgets($this->socket) ?: '';
        }

        if (! str_contains($response, ' 101 ')) {
            throw new RuntimeException("The DevTools handshake failed:\n{$response}");
        }

        stream_set_timeout($this->socket, 60);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function send(string $method, array $params = []): array
    {
        $id = ++$this->id;
        $this->write((string) json_encode(['id' => $id, 'method' => $method, 'params' => (object) $params]));

        while (true) {
            $message = json_decode($this->read(), true);
            $this->dispatch($message);

            if (($message['id'] ?? null) === $id) {
                if (isset($message['error'])) {
                    throw new RuntimeException("{$method}: {$message['error']['message']}");
                }

                return $message['result'] ?? [];
            }
        }
    }

    public function navigate(string $url): void
    {
        $this->send('Page.navigate', ['url' => $url]);

        // Load events arrive out of band; read until one does.
        $deadline = microtime(true) + 30;

        while (microtime(true) < $deadline) {
            $message = json_decode($this->read(), true);
            $this->dispatch($message);

            if (($message['method'] ?? null) === 'Page.loadEventFired') {
                usleep(500000);

                return;
            }
        }
    }

    /**
     * @param  array<string, string>  $constants  Names replaced in the script with JSON-encoded values.
     */
    public function evaluate(string $script, array $constants = []): mixed
    {
        foreach ($constants as $name => $value) {
            $script = str_replace($name, (string) json_encode($value), $script);
        }

        $result = $this->send('Runtime.evaluate', ['expression' => $script, 'returnByValue' => true, 'awaitPromise' => true]);

        return $result['result']['value'] ?? null;
    }

    public function waitFor(callable $condition, int $seconds): void
    {
        $deadline = microtime(true) + $seconds;

        while (microtime(true) < $deadline) {
            if ($condition()) {
                return;
            }

            usleep(500000);
        }

        throw new RuntimeException('Timed out waiting for the page.');
    }

    private function write(string $payload): void
    {
        $length = strlen($payload);
        $frame = chr(0x81);

        if ($length < 126) {
            $frame .= chr(0x80 | $length);
        } elseif ($length < 65536) {
            $frame .= chr(0x80 | 126).pack('n', $length);
        } else {
            $frame .= chr(0x80 | 127).pack('J', $length);
        }

        $mask = random_bytes(4);
        $masked = '';

        for ($i = 0; $i < $length; $i++) {
            $masked .= $payload[$i] ^ $mask[$i % 4];
        }

        fwrite($this->socket, $frame.$mask.$masked);
    }

    private function read(): string
    {
        $message = '';

        do {
            $header = $this->bytes(2);
            $final = (ord($header[0]) & 0x80) !== 0;
            $opcode = ord($header[0]) & 0x0F;
            $length = ord($header[1]) & 0x7F;

            if ($length === 126) {
                $length = unpack('n', $this->bytes(2))[1];
            } elseif ($length === 127) {
                $length = unpack('J', $this->bytes(8))[1];
            }

            $chunk = $length > 0 ? $this->bytes($length) : '';

            if ($opcode === 0x8) {
                throw new RuntimeException('The DevTools socket closed.');
            }

            if ($opcode === 0x9) {
                // A ping: answer and carry on.
                $this->write($chunk);

                continue;
            }

            $message .= $chunk;
        } while (! $final || $opcode === 0x9);

        return $message;
    }

    private function bytes(int $count): string
    {
        $data = '';

        while (strlen($data) < $count) {
            $more = fread($this->socket, $count - strlen($data));

            if ($more === false || $more === '') {
                $info = stream_get_meta_data($this->socket);

                throw new RuntimeException($info['timed_out'] ? 'The DevTools socket timed out.' : 'The DevTools socket closed.');
            }

            $data .= $more;
        }

        return $data;
    }
}
