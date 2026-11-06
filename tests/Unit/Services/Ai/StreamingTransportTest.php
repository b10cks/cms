<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\Drivers\ChunkStream;
use App\Services\Ai\Drivers\StreamingTransport;
use App\Services\Ai\Exceptions\ProviderTransportException;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Chunk\DataChunk;
use Symfony\Component\HttpClient\Chunk\FirstChunk;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\Response\ResponseStream;

class StreamingTransportTest extends TestCase
{
    #[Test]
    public function each_chunk_is_handed_on_without_waiting_for_the_next(): void
    {
        $sent = [];
        $chunks = (function () use (&$sent) {
            $response = new MockResponse;
            yield $response => new FirstChunk;
            foreach (["data: one\n\n", "data: two\n\n"] as $offset => $content) {
                $sent[] = $content;
                yield $response => new DataChunk($offset, $content);
            }
        })();
        $body = new ChunkStream(new ResponseStream($chunks));

        $this->assertSame("data: one\n\n", $body->read(64 * 1024));
        $this->assertCount(1, $sent);
        $this->assertSame("data: two\n\n", $body->read(64 * 1024));
        $this->assertSame('', $body->read(64 * 1024));
        $this->assertTrue($body->eof());
    }

    #[Test]
    public function a_call_that_never_left_is_told_apart_from_one_the_provider_may_have_billed(): void
    {
        $failure = function (array $info) {
            $transport = new StreamingTransport(new MockHttpClient(new MockResponse('', ['error' => 'Could not resolve host', ...$info])));

            try {
                $transport->send(new Request('POST', 'https://provider.test/chat', [], '{}'));
            } catch (ProviderTransportException $e) {
                return $e;
            }

            $this->fail('The call should have failed.');
        };

        $this->assertFalse($failure(['size_upload' => 0.0])->reached);
        $this->assertTrue($failure(['size_upload' => 2.0])->reached);
    }
}
