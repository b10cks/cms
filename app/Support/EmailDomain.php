<?php

namespace App\Support;

use Illuminate\Support\Str;

/** Normalises domain names the way team_domains stores them: lowercase ASCII, IDNs as punycode. */
final class EmailDomain
{
    /**
     * Accepts "Example.COM", "@example.com", "https://example.com/", "bücher.de".
     * Returns null when the input is not a registrable host name.
     */
    public static function normalize(string $input): ?string
    {
        $domain = Str::of($input)->trim()->lower()
            ->replaceMatches('#^[a-z][a-z0-9+.-]*://#', '')
            ->ltrim('@')
            ->before('/')
            ->rtrim('.')
            ->toString();

        if ($domain === '') {
            return null;
        }

        $ascii = idn_to_ascii($domain, IDNA_DEFAULT | IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
        if ($ascii === false || strlen($ascii) > 253) {
            return null;
        }

        // At least two labels, each 1–63 chars of letters, digits and inner hyphens.
        $label = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';
        if (! preg_match("/^(?:{$label}\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/", $ascii)) {
            return null;
        }

        return $ascii;
    }

    public static function fromEmail(string $email): ?string
    {
        if (! str_contains($email, '@')) {
            return null;
        }

        return self::normalize(Str::afterLast($email, '@'));
    }

    public static function isPublicProvider(string $domain): bool
    {
        return in_array($domain, config('teams.domains.public_email_providers', []), true);
    }
}
