<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Asset Distribution (download packages & public share links)
    |--------------------------------------------------------------------------
    */

    // How long a built zip package stays available on the transfers disk
    // before it is pruned (assets:prune-packages) and rebuilt on demand.
    'package_expiry_days' => (int) env('ASSET_PACKAGE_EXPIRY_DAYS', 7),

    // Lifetime of a single issued download URL (CloudFront-signed or S3
    // presigned fallback).
    'download_url_ttl_minutes' => (int) env('ASSET_PACKAGE_URL_TTL_MINUTES', 15),

    // Lifetime of the HMAC access token issued by unlocking a
    // password-protected share.
    'access_token_ttl_minutes' => (int) env('ASSET_SHARE_ACCESS_TOKEN_TTL_MINUTES', 60),

    // How long after a failed package build a new build may be dispatched for
    // the same share. Prevents the public download endpoint's polling from
    // re-dispatching a failing build on every request.
    'failed_build_cooldown_minutes' => (int) env('ASSET_PACKAGE_FAILED_BUILD_COOLDOWN_MINUTES', 10),

    // Upper bound on the summed source file size of a package (0 = unlimited).
    // Builds copy the files locally and zip them alongside, so local disk
    // needs roughly twice this amount.
    'max_package_size_mb' => (int) env('ASSET_PACKAGE_MAX_SIZE_MB', 10240),

    // How long public share access events are kept before being pruned.
    'share_event_retention_days' => (int) env('ASSET_SHARE_EVENT_RETENTION_DAYS', 365),
];
