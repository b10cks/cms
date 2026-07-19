<?php

return [
    'inviteSpace' => [
        'subject' => 'You\'ve been invited to :space',
        'intro' => '<strong>:inviter</strong> has invited you to collaborate on the <strong>:space</strong> space.',
        'start' => 'Click the button below to accept the invitation and get started.',
        'action' => 'Accept Invitation',
        'outro' => 'This invitation will expire :expires.',
    ],
    'inviteTeam' => [
        'subject' => 'You\'ve been invited to :team',
        'intro' => '<strong>:inviter</strong> has invited you to join the <strong>:team</strong> team.',
        'start' => 'Click the button below to accept the invitation and get started.',
        'action' => 'Accept Invitation',
        'outro' => 'This invitation will expire :expires.',
    ],
    'oneTimeToken' => [
        'subject' => 'Your one-time login code: :code',
        'greeting' => 'Hello',
        'intro' => 'Your one-time login code is below. This code will expire in 10 minutes.',
        'outro' => 'If you did not request this code, you can safely ignore this email.',
    ],
    'usageWarning' => [
        'subject' => ':space nærmer sig sin grænse for :metric',
        'intro' => 'Dit space <strong>:space</strong> har brugt <strong>:percentage%</strong> af sin månedlige kvote for :metric.',
        'detail' => ':used af :limit brugt. Intet er blokeret — dette er blot en advarsel, så du kan handle, før grænsen nås.',
        'action' => 'Se forbrug & planer',
        'outro' => 'Overvej at opgradere din plan, hvis du forventer, at dette forbrug fortsætter.',
    ],
    'usageExceeded' => [
        'subject' => ':space har overskredet sin grænse for :metric',
        'intro' => 'Dit space <strong>:space</strong> har brugt <strong>:percentage%</strong> af sin månedlige kvote for :metric.',
        'detail' => ':used af :limit brugt. Din tjeneste fortsætter uden afbrydelser indtil videre, men opgrader venligst til en plan, der passer til dit forbrug.',
        'action' => 'Opgrader plan',
        'outro' => 'Vedvarende overforbrug kan kræve et skifte til en højere plan.',
    ],
    'usageMetrics' => [
        'storage' => 'lagerplads',
        'traffic' => 'trafik',
        'ai' => 'AI-kreditter',
    ],
    'billingIntervals' => [
        'month' => 'måned',
        'year' => 'år',
    ],
    'paymentRequested' => [
        'subject' => 'Betaling anmodet for :space',
        'intro' => '<strong>:requester</strong> beder dig om at overtage abonnementet for spacet <strong>:space</strong>.',
        'detail' => 'Foreslået plan: :plan til :price € / :interval. Du bliver faktureringsansvarlig og modtager alle fakturaer.',
        'action' => 'Gennemse & betal',
        'outro' => 'Du kan vælge en anden plan på abonnementssiden, hvis denne ikke passer.',
        'inviteMessage' => ':requester beder dig om at overtage abonnementet for ":space" (plan: :plan). Når du er kommet med, skal du åbne spacets abonnementsindstillinger for at gennemføre betalingen.',
    ],
];
