<?php

namespace App\Services\Ai\Exceptions;

/** What is known about why a provider call failed, from structured status and codes only. */
enum ProviderFailureKind: string
{
    /** The provider refused the request for now; nothing was generated. */
    case RateLimited = 'rate_limited';
    /** The provider answered with an outage status before generating anything. */
    case Unavailable = 'unavailable';
    /** The request never reached the provider. */
    case Connection = 'connection';
    /** The provider took the request and it ended early. It may be billed in full. */
    case Interrupted = 'interrupted';
    case Auth = 'auth';
    case Quota = 'quota';
    /** The provider refuses this request as it is: schema, size, model. */
    case Rejected = 'rejected';
    /** Nothing says what happened, so nothing may be assumed. */
    case Unknown = 'unknown';

    public function isTransient(): bool
    {
        return \in_array($this, [self::RateLimited, self::Unavailable, self::Connection, self::Interrupted], true);
    }

    /** Whether repeating the call can add spend that no recorded usage shows. */
    public function possiblyBilled(): bool
    {
        return $this === self::Interrupted;
    }

    /**
     * Failures of the provider or its setup say nothing about the entries of a
     * batch. A rejected request can be caused by an entry, an unknown one might be.
     */
    public function sparesEntries(): bool
    {
        return $this->isTransient() || $this === self::Auth || $this === self::Quota;
    }
}
