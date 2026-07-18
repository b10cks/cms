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
        'subject' => ':space nadert zijn limiet voor :metric',
        'intro' => 'Je space <strong>:space</strong> heeft <strong>:percentage%</strong> van zijn maandelijkse tegoed voor :metric gebruikt.',
        'detail' => ':used van :limit gebruikt. Er is niets geblokkeerd — dit is slechts een waarschuwing zodat je kunt ingrijpen voordat de limiet is bereikt.',
        'action' => 'Bekijk gebruik & abonnementen',
        'outro' => 'Overweeg een upgrade van je abonnement als je verwacht dat dit gebruik aanhoudt.',
    ],
    'usageExceeded' => [
        'subject' => ':space heeft zijn limiet voor :metric overschreden',
        'intro' => 'Je space <strong>:space</strong> heeft <strong>:percentage%</strong> van zijn maandelijkse tegoed voor :metric gebruikt.',
        'detail' => ':used van :limit gebruikt. Je service blijft voorlopig gewoon doorlopen, maar upgrade alsjeblieft naar een abonnement dat bij je gebruik past.',
        'action' => 'Abonnement upgraden',
        'outro' => 'Bij aanhoudende overschrijding kan een hoger abonnement nodig zijn.',
    ],
    'usageMetrics' => [
        'storage' => 'opslag',
        'traffic' => 'dataverkeer',
        'requests' => 'API-verzoeken',
        'ai' => 'AI-credits',
    ],
];
