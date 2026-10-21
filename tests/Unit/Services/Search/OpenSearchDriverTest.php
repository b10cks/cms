<?php

namespace Tests\Unit\Services\Search;

use App\Actions\Content\TransformContentToSearchable;
use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Services\Search\OpenSearchDriver;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OpenSearchDriverTest extends TestCase
{
    #[Test]
    public function sync_sends_one_bulk_request_and_returns_only_the_real_failures(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode(['errors' => true, 'items' => [
                ['index' => ['_id' => 'live', 'status' => 201]],
                ['index' => ['_id' => 'broken', 'status' => 400, 'error' => ['reason' => 'mapper_parsing_exception']]],
                ['delete' => ['_id' => 'gone', 'status' => 404]],
            ]])),
        ]));
        $stack->push(Middleware::history($history));

        $transformer = \Mockery::mock(TransformContentToSearchable::class);
        $transformer->shouldReceive('execute')->andReturn('text');
        $driver = new OpenSearchDriver($transformer);
        (fn () => $this->client = new Client(['handler' => $stack]))->call($driver);

        $space = new Space;
        $space->id = 'space-1';

        $failed = $driver->syncContents(collect([
            $this->content('live', published: true),
            $this->content('broken', published: true),
            $this->content('gone', published: false),
        ]), $space);

        $this->assertSame(['broken' => 'mapper_parsing_exception'], $failed);
        $this->assertCount(1, $history);

        $request = $history[0]['request'];
        $this->assertSame('space_space-1/_bulk', ltrim($request->getUri()->getPath(), '/'));
        $lines = array_map(fn (string $line) => json_decode($line, true), explode("\n", trim((string) $request->getBody())));
        $this->assertSame(['index' => ['_id' => 'live']], $lines[0]);
        $this->assertSame('text', $lines[1]['searchable_content']);
        $this->assertSame(['delete' => ['_id' => 'gone']], $lines[4]);
    }

    private function content(string $id, bool $published): Content
    {
        $content = new Content;
        $content->id = $id;
        $content->name = $id;
        $content->published_at = $published ? now() : null;

        return $content;
    }
}
