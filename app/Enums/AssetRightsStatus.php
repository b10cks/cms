<?php

namespace App\Enums;

use Carbon\CarbonInterface;

enum AssetRightsStatus: string
{
    case UNRESTRICTED = 'unrestricted';
    case RESTRICTED = 'restricted';
    case EXPIRED = 'expired';

    /**
     * Derive the rights status for a given license expiry date: no date means
     * unrestricted, a future (or present) date means restricted, a past date
     * means expired.
     */
    public static function fromExpiry(?CarbonInterface $expiresAt): self
    {
        if ($expiresAt === null) {
            return self::UNRESTRICTED;
        }

        return $expiresAt->isPast() ? self::EXPIRED : self::RESTRICTED;
    }
}
