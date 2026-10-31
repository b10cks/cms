<?php

namespace App\Enums;

/** What happens when someone with an email on a team's verified domain signs in. */
enum TeamDomainPolicy: string
{
    /** They may ask to join; a team member manager approves. */
    case Request = 'request';

    /** They join with the domain's default role on their next verified sign-in. */
    case AutoJoin = 'auto_join';

    /** Password and social sign-in are refused; they must use the team's SSO. */
    case RequireSso = 'require_sso';
}
