<?php

namespace App\Services\Storage\Filters;

use HashContext;

/**
 * A self-registered PHP stream filter that feeds every chunk of data passing
 * through the stream into a hash context (created via `hash_init()` and
 * passed as the filter's `$params`), without altering the data itself.
 *
 * This lets us compute a checksum for a file while it is being streamed to
 * storage, instead of requiring a separate read pass just for hashing (ext-hash's
 * built-in `hash.*` stream filters are not guaranteed to be registered on every
 * PHP build, so we register our own instead of relying on them).
 */
class HashingStreamFilter extends \php_user_filter
{
    public const NAME = 'checksum.hash';

    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        stream_filter_register(self::NAME, self::class);
        self::$registered = true;
    }

    public function filter($in, $out, &$consumed, bool $closing): int
    {
        /** @var HashContext $context */
        $context = $this->params;

        while ($bucket = stream_bucket_make_writeable($in)) {
            hash_update($context, $bucket->data);
            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }

        return PSFS_PASS_ON;
    }
}
