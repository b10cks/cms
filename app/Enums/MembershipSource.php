<?php

namespace App\Enums;

/**
 * How a team membership came to exist.
 *
 * This is a trust question, not bookkeeping. A team's SAML provider may sign an
 * assertion for any address it likes, so the email fallback in
 * SamlLoginService only accepts users the team can show a claim to. An invite
 * the user accepted, or an account the team's own IdP provisioned, is such a
 * claim. A membership the team simply attached is not: it says what the team
 * asserts about someone else, which is what the provider was already asserting.
 */
enum MembershipSource: string
{
    /** The user accepted a mailed invitation from the team. */
    case Invite = 'invite';

    /** The team was created by this user, or is their personal team. */
    case Owner = 'owner';

    /** Just-in-time provisioned by the team's own identity provider. */
    case Saml = 'saml';

    /** An administrator attached an existing account directly. */
    case Direct = 'direct';

    /**
     * Sources that prove the team and the user both agreed to the membership,
     * and may therefore back a SAML assertion about that user's address.
     *
     * @return array<int, string>
     */
    public static function samlTrusted(): array
    {
        return [
            self::Invite->value,
            self::Owner->value,
            self::Saml->value,
        ];
    }
}
