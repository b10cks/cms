<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Edition
    |--------------------------------------------------------------------------
    |
    | "saas" is the hosted b10cks.com deployment; "self-hosted" disables the
    | billing surface (LemonSqueezy webhooks, subscription UI, usage/AI-key
    | reconciliation crons) and expects a single unlimited plan.
    |
    */

    'edition' => env('B10CKS_EDITION', 'saas'),

    /*
    |--------------------------------------------------------------------------
    | Feature overrides
    |--------------------------------------------------------------------------
    |
    | null derives the flag from the edition (billing) or from the runtime
    | configuration (ai, realtime). Set an explicit boolean to override.
    |
    */

    'features' => [
        'billing' => env('B10CKS_FEATURE_BILLING'),
        'ai' => env('B10CKS_FEATURE_AI'),
        // Open self-registration. null: always on for saas; on self-hosted
        // only until the first account exists (invites keep working).
        'registration' => env('B10CKS_ALLOW_REGISTRATION'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Imprint
    |--------------------------------------------------------------------------
    |
    | Rendered in outgoing mail footers. When "company" is empty the footer
    | block is omitted entirely, so self-hosted installs send neutral mail.
    |
    */

    'imprint' => [
        'company' => env('B10CKS_IMPRINT_COMPANY'),
        'address' => env('B10CKS_IMPRINT_ADDRESS'),
        'notice' => env('B10CKS_IMPRINT_NOTICE'),
    ],

];
