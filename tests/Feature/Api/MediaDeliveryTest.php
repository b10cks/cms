<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Covers the non-image delivery path: byte ranges, conditional requests,
 * disposition and the type-scoped security headers.
 */
class MediaDeliveryTest extends TestCase
{
    private const string VIDEO_BODY = 'video-bytes-0123456789';

    private const string VIDEO_URL = '/ilum/storage/space/asset/clip.mp4';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('filesystems.default', 'local');
        Storage::fake('local');

        Storage::disk('local')->put('space/asset/clip.mp4', self::VIDEO_BODY);
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
    public function it_serves_media_inline_without_a_sandbox_policy(): void
    {
        $response = $this->get(self::VIDEO_URL);

        $response->assertHeader('x-content-type-options', 'nosniff');
        $this->assertNull($response->headers->get('content-security-policy'));
        $this->assertNull($response->headers->get('content-disposition'));
    }

    #[Test]
    public function it_keeps_the_sandbox_policy_for_scriptable_types(): void
    {
        Storage::disk('local')->put('space/asset/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');

        $response = $this->get('/ilum/storage/space/asset/logo.svg');

        $response->assertOk();
        $this->assertStringContainsString('sandbox', (string) $response->headers->get('content-security-policy'));
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
}
