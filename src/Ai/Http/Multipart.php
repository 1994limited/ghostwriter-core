<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai\Http;

/**
 * A multipart/form-data body, for uploads such as OpenAI's image edits.
 */
final class Multipart
{
    public readonly string $boundary;

    /** @var array<int, array{name: string, contents: string, filename: ?string, type: ?string}> */
    private array $parts = [];

    public function __construct(?string $boundary = null)
    {
        $this->boundary = $boundary ?? bin2hex(random_bytes(16));
    }

    public function add(string $name, string $contents, ?string $filename = null, ?string $contentType = null): self
    {
        $this->parts[] = ['name' => $name, 'contents' => $contents, 'filename' => $filename, 'type' => $contentType];

        return $this;
    }

    public function contentType(): string
    {
        return 'multipart/form-data; boundary='.$this->boundary;
    }

    public function body(): string
    {
        $body = '';

        foreach ($this->parts as $part) {
            $disposition = 'form-data; name="'.$this->escape($part['name']).'"';

            if ($part['filename'] !== null) {
                $disposition .= '; filename="'.$this->escape($part['filename']).'"';
            }

            $body .= "--{$this->boundary}\r\nContent-Disposition: {$disposition}\r\n";

            if ($part['type'] !== null) {
                $body .= "Content-Type: {$part['type']}\r\n";
            }

            $body .= "\r\n{$part['contents']}\r\n";
        }

        return $body."--{$this->boundary}--\r\n";
    }

    /**
     * @return array<int, array{name: string, contents: string, filename: ?string, type: ?string}>
     */
    public function parts(): array
    {
        return $this->parts;
    }

    private function escape(string $value): string
    {
        return str_replace(['"', "\r", "\n"], ['%22', '%0D', '%0A'], $value);
    }
}
