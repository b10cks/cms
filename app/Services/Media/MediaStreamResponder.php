<?php

namespace App\Services\Media;

use App\Services\Media\Dto\StoredFile;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a stored file to the client with the semantics media players expect.
 *
 * The important part is HTTP range support: without a 206 Safari refuses to
 * play video at all, no browser can seek, and every request pins a PHP worker
 * for the length of a full-file transfer. Ranges also let the CDN fetch a
 * large object in slices instead of one origin pull that outlives the origin
 * response timeout.
 */
class MediaStreamResponder
{
    /**
     * Types exempt from the restrictive sandbox CSP.
     *
     * This is deliberately an allow-list: these routes serve user-uploaded
     * bytes from an origin shared with the management UI, so anything we have
     * not explicitly vetted must keep the sandbox. PDF is exempt because the
     * sandbox directive breaks the built-in viewer's controls, and the viewer
     * already isolates embedded script from the embedding origin.
     */
    private const array SANDBOX_EXEMPT_TYPES = [
        'application/pdf',
    ];

    /**
     * The policy the exempt types carry instead.
     *
     * Sending no policy at all is not the same as sending none: {@see \App\Http\Middleware\SecurityHeaders}
     * falls back to `frame-ancestors 'none'` plus `X-Frame-Options: DENY` for
     * any response that has not decided for itself, which would stop a delivered
     * PDF from being embedded in a customer's page — the ordinary way to show
     * one. Stating the policy here keeps the resource restrictions, drops the
     * `sandbox` directive that breaks the viewer, and keeps framing open the way
     * a public asset URL needs.
     */
    private const string EXEMPT_TYPE_CSP = "default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; frame-ancestors *";

    /**
     * Sentinel distinguishing "no range requested" (null) from "range header
     * present but unsatisfiable" (416).
     */
    private const string UNSATISFIABLE = 'unsatisfiable';

    /**
     * Answer with a body already held in memory (a freshly transformed image).
     *
     * Ranges are pointless here — the bytes are generated, not streamed — but
     * the validators still matter: hashing the buffer gives an exact ETag, so
     * a repeat request costs a 304 instead of a re-transform's worth of bytes.
     */
    public function respondWithBody(
        Request $request,
        string $body,
        string $mime,
        bool $immutable,
        ?int $maxAge = null,
        ?string $downloadName = null,
    ): Response {
        $file = new StoredFile(
            path: '',
            mime: $mime,
            size: null,
            etag: '"'.md5($body).'"',
            lastModified: null,
            downloadName: $downloadName,
        );

        $headers = $this->baseHeaders($request, $file, $immutable, $maxAge);

        if ($notModified = $this->notModifiedResponse($request, $file, $headers)) {
            return $notModified;
        }

        $headers['content-length'] = (string) strlen($body);

        return new Response($body, Response::HTTP_OK, $headers);
    }

    public function respond(
        Request $request,
        FilesystemAdapter $disk,
        StoredFile $file,
        bool $immutable,
        ?int $maxAge = null,
    ): Response {
        $headers = $this->baseHeaders($request, $file, $immutable, $maxAge);

        if ($notModified = $this->notModifiedResponse($request, $file, $headers)) {
            return $notModified;
        }

        $range = $file->supportsRanges()
            ? $this->resolveRange($request, $file)
            : null;

        if ($range === self::UNSATISFIABLE) {
            return new Response(null, Response::HTTP_REQUESTED_RANGE_NOT_SATISFIABLE, [
                ...$headers,
                'content-range' => 'bytes */'.$file->size,
            ]);
        }

        [$start, $length] = $range ?? [0, $file->size];

        if ($range !== null) {
            $headers['content-range'] = sprintf('bytes %d-%d/%d', $start, $start + $length - 1, $file->size);
        }

        if ($length !== null) {
            $headers['content-length'] = (string) $length;
        }

        // Open eagerly: once the status line and Content-Length are on the
        // wire we can no longer turn a failure into a 404, and a silently
        // truncated 200 would be cached as if it were the real file.
        $stream = $this->openStream($disk, $file->path, $start, $length, $range !== null);

        if ($stream === null) {
            return new Response(null, Response::HTTP_NOT_FOUND);
        }

        return new StreamedResponse(
            fn () => $this->stream($stream, $file->path, $length),
            $range !== null ? Response::HTTP_PARTIAL_CONTENT : Response::HTTP_OK,
            $headers,
        );
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function notModifiedResponse(Request $request, StoredFile $file, array $headers): ?Response
    {
        $ifNoneMatch = $request->headers->get('if-none-match');

        if ($ifNoneMatch !== null && $file->etag !== null) {
            return $this->etagMatches($ifNoneMatch, $file->etag)
                ? new Response(null, Response::HTTP_NOT_MODIFIED, $headers)
                : null;
        }

        // Only fall back to the date validator when no entity tag was offered;
        // per RFC 9110 If-None-Match takes precedence when both are present.
        $ifModifiedSince = $request->headers->get('if-modified-since');

        if ($ifNoneMatch === null && $ifModifiedSince !== null && $file->lastModified !== null) {
            $since = strtotime($ifModifiedSince);

            if ($since !== false && $file->lastModified <= $since) {
                return new Response(null, Response::HTTP_NOT_MODIFIED, $headers);
            }
        }

        return null;
    }

    /**
     * Resolve the requested byte range.
     *
     * Returns null to serve the whole entity, the UNSATISFIABLE sentinel for a
     * 416, or [start, length]. Multi-range requests deliberately fall back to a
     * full response — RFC 9110 permits ignoring Range, and multipart/byteranges
     * buys nothing for media playback.
     *
     * @return array{0: int, 1: int}|string|null
     */
    private function resolveRange(Request $request, StoredFile $file): array|string|null
    {
        $header = $request->headers->get('range');

        if ($header === null) {
            return null;
        }

        // If-Range makes the range conditional on the entity being unchanged;
        // when it no longer matches, the client expects the full entity back.
        $ifRange = $request->headers->get('if-range');

        if ($ifRange !== null && ! $this->ifRangeMatches($ifRange, $file)) {
            return null;
        }

        if (! preg_match('/^bytes=(\d*)-(\d*)$/i', trim($header), $matches)) {
            return null;
        }

        $size = (int) $file->size;
        [$rawStart, $rawEnd] = [$matches[1], $matches[2]];

        if ($rawStart === '' && $rawEnd === '') {
            return null;
        }

        if ($rawStart === '') {
            // Suffix range: the last N bytes.
            $suffix = (int) $rawEnd;

            if ($suffix === 0) {
                return self::UNSATISFIABLE;
            }

            $start = max(0, $size - $suffix);
            $end = $size - 1;
        } else {
            $start = (int) $rawStart;

            if ($start >= $size) {
                return self::UNSATISFIABLE;
            }

            $end = $rawEnd === '' ? $size - 1 : min((int) $rawEnd, $size - 1);
        }

        if ($end < $start) {
            return self::UNSATISFIABLE;
        }

        return [$start, $end - $start + 1];
    }

    private function ifRangeMatches(string $ifRange, StoredFile $file): bool
    {
        if ($file->etag !== null && $this->etagMatches($ifRange, $file->etag)) {
            return true;
        }

        if ($file->lastModified === null) {
            return false;
        }

        $date = strtotime($ifRange);

        return $date !== false && $date === $file->lastModified;
    }

    /**
     * Compare an If-None-Match / If-Range value against our entity tag,
     * ignoring the weak prefix as required for weak comparison.
     */
    private function etagMatches(string $candidate, string $etag): bool
    {
        $normalize = static fn (string $value): string => ltrim(trim($value), 'W/');

        foreach (explode(',', $candidate) as $value) {
            $value = trim($value);

            if ($value === '*' || $normalize($value) === $normalize($etag)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    private function baseHeaders(Request $request, StoredFile $file, bool $immutable, ?int $maxAge): array
    {
        $headers = [
            'access-control-allow-origin' => '*',
            'access-control-allow-methods' => 'GET, HEAD, OPTIONS',
            'access-control-expose-headers' => 'accept-ranges, content-range, content-length, etag',
            'content-type' => $file->mime,
            'cache-control' => $this->cacheControl($immutable, $maxAge),
            'pragma' => 'public',
            ...$this->securityHeaders($file->mime),
        ];

        if ($file->supportsRanges()) {
            $headers['accept-ranges'] = 'bytes';
        }

        if ($file->etag !== null) {
            $headers['etag'] = $file->etag;
        }

        if ($file->lastModified !== null) {
            $headers['last-modified'] = gmdate('D, d M Y H:i:s', $file->lastModified).' GMT';
        }

        if ($request->boolean('download')) {
            $headers['content-disposition'] = $this->contentDisposition($file);
        }

        return $headers;
    }

    private function contentDisposition(StoredFile $file): string
    {
        $name = $file->downloadName ?: basename($file->path);

        // Strip anything that could break out of the quoted-string, and offer
        // the UTF-8 form alongside an ASCII fallback for older clients.
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $name) ?: 'download';
        $ascii = str_replace(['"', '\\'], '_', $ascii);

        return sprintf('attachment; filename="%s"; filename*=UTF-8\'\'%s', $ascii, rawurlencode($name));
    }

    /**
     * @return array<string, string>
     */
    private function securityHeaders(string $mime): array
    {
        $headers = ['x-content-type-options' => 'nosniff'];

        $type = strtolower(trim(explode(';', $mime)[0]));

        $headers['content-security-policy'] = in_array($type, self::SANDBOX_EXEMPT_TYPES, true)
            ? self::EXEMPT_TYPE_CSP
            : "default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; sandbox";

        return $headers;
    }

    private function cacheControl(bool $immutable, ?int $maxAge): string
    {
        $duration = $maxAge ?? (int) config('ilum.cache.duration', 31_536_000);

        return 'public, max-age='.$duration.($immutable ? ', immutable' : '');
    }

    /**
     * Copy up to $length bytes from an already-positioned stream to the client
     * in bounded chunks, so a large file never has to be held in memory.
     *
     * @param  resource  $stream
     */
    private function stream($stream, string $path, ?int $length): void
    {
        // A large transfer over a slow connection legitimately outlives the
        // default limit, but an unbounded one lets a stalled client pin a
        // worker forever.
        set_time_limit(max(0, (int) config('ilum.stream.max_seconds', 900)));

        $chunkSize = max(8192, (int) config('ilum.stream.chunk_size', 1_048_576));
        $remaining = $length;
        $aborted = false;

        try {
            while (! feof($stream) && ($remaining === null || $remaining > 0)) {
                $read = $remaining === null ? $chunkSize : min($chunkSize, $remaining);
                $buffer = fread($stream, $read);

                if ($buffer === false || $buffer === '') {
                    break;
                }

                echo $buffer;

                if ($remaining !== null) {
                    $remaining -= strlen($buffer);
                }

                flush();

                // Stop paying for bytes nobody is listening to.
                if (connection_aborted()) {
                    $aborted = true;

                    break;
                }
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        // We already promised this many bytes in Content-Length. Coming up
        // short means the object changed or the read failed mid-flight; the
        // client sees a truncated response either way, so make it diagnosable.
        if (! $aborted && $remaining !== null && $remaining > 0) {
            Log::error('Media stream ended short of the promised length', [
                'path' => $path,
                'missing' => $remaining,
                'promised' => $length,
            ]);
        }
    }

    /**
     * Open a read stream positioned at $start.
     *
     * S3 gets the offset pushed down into GetObject so the origin never
     * transfers bytes the client did not ask for; other drivers seek locally.
     *
     * @return resource|null
     */
    private function openStream(FilesystemAdapter $disk, string $path, int $start, ?int $length, bool $ranged)
    {
        if ($ranged && ($s3Stream = $this->openS3RangeStream($disk, $path, $start, $length)) !== null) {
            return $s3Stream;
        }

        $stream = $disk->readStream($path);

        if (! is_resource($stream)) {
            Log::error('Failed to open media stream', ['path' => $path]);

            return null;
        }

        if ($start > 0 && ! $this->seek($stream, $start)) {
            fclose($stream);

            return null;
        }

        return $stream;
    }

    /**
     * @return resource|null
     */
    private function openS3RangeStream(FilesystemAdapter $disk, string $path, int $start, ?int $length)
    {
        if (! $disk instanceof AwsS3V3Adapter) {
            return null;
        }

        $bucket = $disk->getConfig()['bucket'] ?? null;

        if (! $bucket) {
            return null;
        }

        $range = $length === null
            ? sprintf('bytes=%d-', $start)
            : sprintf('bytes=%d-%d', $start, $start + $length - 1);

        try {
            $result = $disk->getClient()->getObject([
                'Bucket' => $bucket,
                'Key' => $disk->path($path),
                'Range' => $range,
                '@http' => ['stream' => true],
            ]);

            $body = $result->get('Body');

            return $body instanceof StreamInterface ? $body->detach() : null;
        } catch (\Throwable $e) {
            Log::warning('Ranged S3 read failed, falling back to seek', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Position a stream at $offset, falling back to reading and discarding
     * when the underlying stream is not seekable.
     *
     * @param  resource  $stream
     */
    private function seek($stream, int $offset): bool
    {
        if ((stream_get_meta_data($stream)['seekable'] ?? false) && fseek($stream, $offset) === 0) {
            return true;
        }

        $discarded = 0;

        while ($discarded < $offset && ! feof($stream)) {
            $chunk = fread($stream, (int) min(1_048_576, $offset - $discarded));

            if ($chunk === false || $chunk === '') {
                return false;
            }

            $discarded += strlen($chunk);
        }

        return $discarded === $offset;
    }
}
