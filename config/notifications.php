<?php

return [

    /*
    |--------------------------------------------------------------------------
    | In-app notification email delay
    |--------------------------------------------------------------------------
    |
    | In-app notifications are delivered instantly (database + broadcast). The
    | email fallback for a registered user is deferred by this many minutes and
    | only sent if the user has not read the notification in-app by the time the
    | queued mail job runs. This keeps email "for when it's needed" only.
    |
    */

    'mail_delay_minutes' => (int) env('NOTIFICATIONS_MAIL_DELAY_MINUTES', 5),

];
