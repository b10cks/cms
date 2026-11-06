<?php

namespace App\Services\Ai\Drivers;

use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * A read-only PSR-7 body over Symfony's response chunks. `read()` returns
 * what has arrived instead of waiting to fill the requested length, so each
 * SSE line is handed on as soon as the provider sends it. A transfer error
 * surfaces from `read()` as Symfony's TransportException.
 */
final class ChunkStream implements StreamInterface
{
    private string $buffer = '';

    private int $position = 0;

    private bool $ended = false;

    private bool $started = false;

    public function __construct(private ?ResponseStreamInterface $chunks) {}

    public function read(int $length): string
    {
        while ($this->buffer === '' && ! $this->ended) {
            $this->pull();
        }

        $data = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, \strlen($data));
        $this->position += \strlen($data);

        return $data;
    }

    public function eof(): bool
    {
        return $this->buffer === '' && $this->ended;
    }

    public function getContents(): string
    {
        $contents = '';
        while (! $this->eof()) {
            $contents .= $this->read(PHP_INT_MAX);
        }

        return $contents;
    }

    public function __toString(): string
    {
        return $this->getContents();
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function close(): void
    {
        $this->chunks = null;
        $this->buffer = '';
        $this->ended = true;
    }

    public function detach()
    {
        $this->close();

        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new RuntimeException('A provider stream cannot seek.');
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new RuntimeException('A provider stream is read-only.');
    }

    public function isReadable(): bool
    {
        return ! $this->ended || $this->buffer !== '';
    }

    public function getMetadata(?string $key = null): mixed
    {
        return $key === null ? [] : null;
    }

    /** Advances only when more is needed: moving past a chunk waits for the next one. */
    private function pull(): void
    {
        if ($this->started) {
            $this->chunks?->next();
        }
        $this->started = true;

        if ($this->chunks === null || ! $this->chunks->valid()) {
            $this->ended = true;

            return;
        }

        $this->buffer .= $this->chunks->current()->getContent();
    }
}
