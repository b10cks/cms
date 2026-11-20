<?php

namespace App\Enums;

/**
 * The three WebAuthn ceremonies the app runs. Each keeps its own pending
 * challenge in the session, so starting one never cancels another.
 */
enum PasskeyCeremony: string
{
    /** Adds a passkey to the signed-in user. */
    case Register = 'register';

    /** Signs a user in with a discoverable passkey, no email needed. */
    case Login = 'login';

    /** Proves the signed-in user is present, as the step-up for a sensitive action. */
    case Confirm = 'confirm';
}
