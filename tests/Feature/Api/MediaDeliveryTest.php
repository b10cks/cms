<?php

namespace Tests\Feature\Api;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Covers the non-image delivery path: byte ranges, conditional requests,
 * disposition and the security headers.
 */
class MediaDeliveryTest extends TestCase
{
    private const string VIDEO_BODY = 'video-bytes-0123456789';

    private const string VIDEO_URL = '/ilum/storage/space/asset/clip.mp4';

    /**
     * Comfortably more than the 8 KiB floor on the stream chunk size, so the
     * copy loop has to iterate. Every other fixture here fits in one chunk and
     * would never exercise it.
     */
    private const int LARGE_SIZE = 20_000;

    private const string LARGE_URL = '/ilum/storage/space/asset/large.mp4';

    protected function setUp(): void
    {
        parent::setUp();

        // The throttle keys on IP, and every test in this class shares one.
        Cache::flush();

        config()->set('filesystems.default', 'local');
        Storage::fake('local');

        Storage::disk('local')->put('space/asset/clip.mp4', self::VIDEO_BODY);
    }

    /**
     * A deterministic body whose every byte encodes its own offset, so a
     * mis-seek or a dropped chunk shows up as a content mismatch rather than
     * just a wrong length.
     */
    private function largeBody(): string
    {
        $body = '';

        for ($i = 0; strlen($body) < self::LARGE_SIZE; $i++) {
            $body .= sprintf('%08d|', $i);
        }

        return substr($body, 0, self::LARGE_SIZE);
    }

    private function putLargeFile(): string
    {
        $body = $this->largeBody();

        Storage::disk('local')->put('space/asset/large.mp4', $body);

        return $body;
    }

    #[Test]
    public function it_advertises_range_support_on_a_full_response(): void
    {
        $response = $this->get(self::VIDEO_URL);

        $response->assertOk()
            ->assertHeader('accept-ranges', 'bytes')
            ->assertHeader('content-type', 'video/mp4')
            ->assertHeader('content-length', (string) strlen(self::VIDEO_BODY));

        $this->assertSame(self::VIDEO_BODY, $response->streamedContent());
    }

    #[Test]
    public function it_serves_a_partial_response_for_a_byte_range(): void
    {
        $response = $this->get(self::VIDEO_URL, ['Range' => 'bytes=6-10']);

        $response->assertStatus(206)
            ->assertHeader('content-range', 'bytes 6-10/'.strlen(self::VIDEO_BODY))
            ->assertHeader('content-length', '5');

        $this->assertSame(substr(self::VIDEO_BODY, 6, 5), $response->streamedContent());
    }

    #[Test]
    public function it_serves_an_open_ended_range_to_the_end_of_the_file(): void
    {
        $response = $this->get(self::VIDEO_URL, ['Range' => 'bytes=15-']);

        $size = strlen(self::VIDEO_BODY);

        $response->assertStatus(206)
            ->assertHeader('content-range', 'bytes 15-'.($size - 1).'/'.$size);

        $this->assertSame(substr(self::VIDEO_BODY, 15), $response->streamedContent());
    }

    #[Test]
    public function it_serves_a_suffix_range(): void
    {
        $response = $this->get(self::VIDEO_URL, ['Range' => 'bytes=-4']);

        $size = strlen(self::VIDEO_BODY);

        $response->assertStatus(206)
            ->assertHeader('content-range', 'bytes '.($size - 4).'-'.($size - 1).'/'.$size);

        $this->assertSame(substr(self::VIDEO_BODY, -4), $response->streamedContent());
    }

    /**
     * Safari probes with a two-byte range before it will play anything.
     */
    #[Test]
    public function it_answers_the_safari_playback_probe_with_a_206(): void
    {
        $response = $this->get(self::VIDEO_URL, ['Range' => 'bytes=0-1']);

        $response->assertStatus(206)
            ->assertHeader('content-range', 'bytes 0-1/'.strlen(self::VIDEO_BODY));

        $this->assertSame(substr(self::VIDEO_BODY, 0, 2), $response->streamedContent());
    }

    #[Test]
    public function it_rejects_a_range_past_the_end_of_the_file(): void
    {
        $this->get(self::VIDEO_URL, ['Range' => 'bytes=9999-'])
            ->assertStatus(416)
            ->assertHeader('content-range', 'bytes */'.strlen(self::VIDEO_BODY));
    }

    #[Test]
    public function it_ignores_a_multi_range_request(): void
    {
        $this->get(self::VIDEO_URL, ['Range' => 'bytes=0-1,5-6'])->assertOk();
    }

    #[Test]
    public function it_returns_not_modified_when_the_etag_matches(): void
    {
        $etag = $this->get(self::VIDEO_URL)->headers->get('etag');

        $this->assertNotNull($etag);

        $this->get(self::VIDEO_URL, ['If-None-Match' => $etag])->assertStatus(304);
    }

    #[Test]
    public function it_returns_the_body_when_the_etag_does_not_match(): void
    {
        $this->get(self::VIDEO_URL, ['If-None-Match' => '"stale"'])->assertOk();
    }

    #[Test]
    public function it_falls_back_to_a_full_response_when_if_range_no_longer_matches(): void
    {
        $this->get(self::VIDEO_URL, ['Range' => 'bytes=6-10', 'If-Range' => '"stale"'])
            ->assertOk();
    }

    #[Test]
    public function it_honours_if_range_when_the_etag_still_matches(): void
    {
        $etag = $this->get(self::VIDEO_URL)->headers->get('etag');

        $this->get(self::VIDEO_URL, ['Range' => 'bytes=6-10', 'If-Range' => $etag])
            ->assertStatus(206);
    }

    #[Test]
    public function it_serves_media_inline(): void
    {
        $response = $this->get(self::VIDEO_URL);

        $response->assertHeader('x-content-type-options', 'nosniff');
        $this->assertNull($response->headers->get('content-disposition'));
    }

    /**
     * The sandbox is an allow-list: an unvetted type keeps it even when it
     * carries no script of its own, because these bytes are user-uploaded and
     * the delivery origin is shared with the management UI.
     */
    #[Test]
    public function it_sandboxes_every_type_that_is_not_explicitly_exempt(): void
    {
        Storage::disk('local')->put('space/asset/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');

        foreach ([self::VIDEO_URL, '/ilum/storage/space/asset/logo.svg'] as $url) {
            $response = $this->get($url);

            $response->assertOk();
            $this->assertStringContainsString(
                'sandbox',
                (string) $response->headers->get('content-security-policy'),
                "Expected a sandbox policy for {$url}",
            );
        }
    }

    #[Test]
    public function it_exempts_pdf_so_the_built_in_viewer_keeps_its_controls(): void
    {
        Storage::disk('local')->put('space/asset/manual.pdf', '%PDF-1.4 fake');

        $response = $this->get('/ilum/storage/space/asset/manual.pdf');

        $response->assertOk()->assertHeader('x-content-type-options', 'nosniff');

        $csp = (string) $response->headers->get('content-security-policy');

        // Not "no policy": SecurityHeaders would then fall back to
        // `frame-ancestors 'none'` and a customer could no longer embed the PDF.
        $this->assertStringNotContainsString('sandbox', $csp);
        $this->assertStringContainsString('frame-ancestors *', $csp);
        $this->assertNull($response->headers->get('x-frame-options'));
    }

    #[Test]
    public function it_sends_an_attachment_disposition_when_download_is_requested(): void
    {
        $response = $this->get(self::VIDEO_URL.'?download=1');

        $response->assertOk();
        $this->assertStringStartsWith('attachment;', (string) $response->headers->get('content-disposition'));
        $this->assertStringContainsString('clip.mp4', (string) $response->headers->get('content-disposition'));
    }

    #[Test]
    public function it_does_not_mark_passthrough_responses_immutable_by_default(): void
    {
        $cacheControl = (string) $this->get(self::VIDEO_URL)->headers->get('cache-control');

        $this->assertStringNotContainsString('immutable', $cacheControl);
        $this->assertStringContainsString('max-age=', $cacheControl);
    }

    // --- Streaming ---------------------------------------------------------

    #[Test]
    public function it_streams_a_file_that_spans_several_chunks_intact(): void
    {
        $body = $this->putLargeFile();

        $response = $this->get(self::LARGE_URL);

        $response->assertOk()->assertHeader('content-length', (string) self::LARGE_SIZE);
        $this->assertSame($body, $response->streamedContent());
    }

    #[Test]
    public function it_streams_a_range_that_spans_a_chunk_boundary(): void
    {
        $body = $this->putLargeFile();

        // Starts inside the first chunk and ends inside the third.
        $response = $this->get(self::LARGE_URL, ['Range' => 'bytes=8000-17000']);

        $response->assertStatus(206)
            ->assertHeader('content-range', 'bytes 8000-17000/'.self::LARGE_SIZE)
            ->assertHeader('content-length', '9001');

        $this->assertSame(substr($body, 8000, 9001), $response->streamedContent());
    }

    #[Test]
    public function it_honours_a_configured_chunk_size(): void
    {
        $body = $this->putLargeFile();

        // Below the 8 KiB floor, so the responder should clamp rather than
        // spin on tiny reads.
        config()->set('ilum.stream.chunk_size', 16);

        $this->assertSame($body, $this->get(self::LARGE_URL)->streamedContent());
    }

    /**
     * Any driver that is neither S3 nor a local file hands back a pipe, and
     * the responder has to reach the range start by discarding bytes instead
     * of seeking.
     */
    #[Test]
    public function it_serves_a_range_from_a_non_seekable_stream(): void
    {
        $body = $this->putLargeFile();
        $root = Storage::disk('local')->path('');

        Storage::extend('nonseekable', function ($app, $config) {
            $adapter = new LocalFilesystemAdapter($config['root']);

            return new class(new Flysystem($adapter), $adapter, $config) extends FilesystemAdapter
            {
                public function readStream($path)
                {
                    return popen('cat '.escapeshellarg($this->path($path)).' 2>/dev/null', 'r');
                }
            };
        });

        config()->set('filesystems.disks.nonseekable', ['driver' => 'nonseekable', 'root' => $root]);
        config()->set('filesystems.default', 'nonseekable');

        $stream = Storage::disk('nonseekable')->readStream('space/asset/large.mp4');
        $this->assertFalse(stream_get_meta_data($stream)['seekable'], 'fixture must not be seekable');
        pclose($stream);

        $response = $this->get(self::LARGE_URL, ['Range' => 'bytes=9000-9500']);

        $response->assertStatus(206)->assertHeader('content-length', '501');
        $this->assertSame(substr($body, 9000, 501), $response->streamedContent());
    }

    // --- Range edge cases --------------------------------------------------

    #[Test]
    public function it_serves_a_single_byte_range(): void
    {
        $response = $this->get(self::VIDEO_URL, ['Range' => 'bytes=0-0']);

        $response->assertStatus(206)->assertHeader('content-length', '1');
        $this->assertSame(self::VIDEO_BODY[0], $response->streamedContent());
    }

    #[Test]
    public function it_clamps_a_range_that_overshoots_the_end(): void
    {
        $size = strlen(self::VIDEO_BODY);

        $response = $this->get(self::VIDEO_URL, ['Range' => 'bytes=5-9999']);

        $response->assertStatus(206)
            ->assertHeader('content-range', 'bytes 5-'.($size - 1).'/'.$size);

        $this->assertSame(substr(self::VIDEO_BODY, 5), $response->streamedContent());
    }

    #[Test]
    public function it_clamps_a_suffix_range_longer_than_the_file(): void
    {
        $size = strlen(self::VIDEO_BODY);

        $response = $this->get(self::VIDEO_URL, ['Range' => 'bytes=-9999']);

        $response->assertStatus(206)
            ->assertHeader('content-range', 'bytes 0-'.($size - 1).'/'.$size);

        $this->assertSame(self::VIDEO_BODY, $response->streamedContent());
    }

    #[Test]
    public function it_rejects_a_zero_length_suffix_range(): void
    {
        $this->get(self::VIDEO_URL, ['Range' => 'bytes=-0'])->assertStatus(416);
    }

    #[Test]
    public function it_rejects_an_inverted_range(): void
    {
        $this->get(self::VIDEO_URL, ['Range' => 'bytes=10-4'])->assertStatus(416);
    }

    /**
     * RFC 9110 lets a server ignore a Range it cannot parse, which is safer
     * than guessing: the client gets the whole entity.
     */
    #[Test]
    public function it_ignores_a_malformed_range_header(): void
    {
        foreach (['bytes=abc-def', 'bytes=-', 'bytes=', 'items=0-5', 'bytes=1-2-3', ''] as $header) {
            $response = $this->get(self::VIDEO_URL, ['Range' => $header]);

            $response->assertOk();
            $this->assertSame(self::VIDEO_BODY, $response->streamedContent(), "Range: {$header}");
        }
    }

    // --- Conditional requests ----------------------------------------------

    #[Test]
    public function it_returns_not_modified_for_a_matching_if_modified_since(): void
    {
        $lastModified = $this->get(self::VIDEO_URL)->headers->get('last-modified');

        $this->assertNotNull($lastModified);

        $this->get(self::VIDEO_URL, ['If-Modified-Since' => $lastModified])->assertStatus(304);
    }

    #[Test]
    public function it_returns_the_body_when_if_modified_since_predates_the_file(): void
    {
        $this->get(self::VIDEO_URL, ['If-Modified-Since' => 'Mon, 01 Jan 2001 00:00:00 GMT'])
            ->assertOk();
    }

    /**
     * Per RFC 9110 an entity tag wins outright when both validators are sent,
     * so a stale tag must produce a body even alongside a fresh date.
     */
    #[Test]
    public function it_prefers_the_entity_tag_over_the_date_validator(): void
    {
        $lastModified = $this->get(self::VIDEO_URL)->headers->get('last-modified');

        $this->get(self::VIDEO_URL, [
            'If-None-Match' => '"stale"',
            'If-Modified-Since' => $lastModified,
        ])->assertOk();
    }

    #[Test]
    public function it_treats_a_wildcard_if_none_match_as_a_hit(): void
    {
        $this->get(self::VIDEO_URL, ['If-None-Match' => '*'])->assertStatus(304);
    }

    #[Test]
    public function it_matches_an_entity_tag_offered_in_a_list(): void
    {
        $etag = $this->get(self::VIDEO_URL)->headers->get('etag');

        $this->get(self::VIDEO_URL, ['If-None-Match' => '"other", '.$etag])->assertStatus(304);
    }

    /**
     * The validator a local file gets is already weak; a client echoing it
     * back with or without the prefix has to compare equal either way.
     */
    #[Test]
    public function it_compares_entity_tags_weakly(): void
    {
        $etag = (string) $this->get(self::VIDEO_URL)->headers->get('etag');

        $this->assertStringStartsWith('W/', $etag, 'local files get a synthesised weak tag');

        $this->get(self::VIDEO_URL, ['If-None-Match' => ltrim($etag, 'W/')])->assertStatus(304);
    }

    #[Test]
    public function it_keeps_the_validators_on_a_not_modified_response(): void
    {
        $etag = $this->get(self::VIDEO_URL)->headers->get('etag');

        $response = $this->get(self::VIDEO_URL, ['If-None-Match' => $etag]);

        $response->assertStatus(304)->assertHeader('etag', $etag);
        $this->assertStringContainsString('max-age=', (string) $response->headers->get('cache-control'));
    }

    // --- Headers -----------------------------------------------------------

    #[Test]
    public function it_answers_a_head_request_with_the_headers_but_no_body(): void
    {
        $response = $this->head(self::VIDEO_URL);

        $response->assertOk()
            ->assertHeader('accept-ranges', 'bytes')
            ->assertHeader('content-length', (string) strlen(self::VIDEO_BODY));

        $this->assertSame('', $response->streamedContent());
    }

    #[Test]
    public function it_exposes_the_range_headers_to_cross_origin_readers(): void
    {
        $response = $this->get(self::VIDEO_URL);

        $response->assertHeader('access-control-allow-origin', '*');

        $exposed = (string) $response->headers->get('access-control-expose-headers');

        foreach (['accept-ranges', 'content-range', 'content-length', 'etag'] as $header) {
            $this->assertStringContainsString($header, $exposed);
        }
    }

    /**
     * The URL grammar cannot express a name outside this charset, so the only
     * route to a hostile download name is the asset's stored metadata — see
     * PosterDeliveryTest for that half.
     */
    #[Test]
    public function it_refuses_to_route_a_name_outside_the_allowed_charset(): void
    {
        foreach (['a%22b.mp4', 'a%5Cb.mp4', 'vid%C3%A9o.mp4'] as $name) {
            $this->get('/ilum/storage/space/asset/'.$name)->assertNotFound();
        }
    }

    /**
     * `.` and `..` do satisfy the route charset, and Laravel decodes the path
     * before matching, so `..%2F` splits into a routable extra segment. The
     * resulting path normalises to a directory, which must be a clean 404
     * rather than an exception escaping from the size probe.
     */
    #[Test]
    public function it_refuses_a_traversal_segment_instead_of_erroring(): void
    {
        $traversals = [
            '/ilum/storage/space/asset/..',
            '/ilum/storage/space/asset/.',
            '/ilum/storage/space/asset/..%2Fescape.mp4',
            '/ilum/storage/space/asset/..%2F..%2Fescape.mp4',
            '/ilum/storage/space/..%2Fasset/clip.mp4',
        ];

        foreach ($traversals as $url) {
            $this->get($url)->assertNotFound();
        }
    }

    /**
     * A directory is not a file even when nothing traversed to reach it.
     */
    #[Test]
    public function it_returns_404_for_a_directory_path(): void
    {
        Storage::disk('local')->put('space/asset/nested/inner.mp4', 'x');

        $this->get('/ilum/storage/space/asset/nested')->assertNotFound();
    }

    #[Test]
    public function it_throttles_delivery_requests(): void
    {
        config()->set('ilum.rate_limit', 2);

        $this->get(self::VIDEO_URL)->assertOk();
        $this->get(self::VIDEO_URL)->assertOk();
        $this->get(self::VIDEO_URL)->assertStatus(429);
    }
}
