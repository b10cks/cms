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
        'subject' => ':space si sta avvicinando al limite di :metric',
        'intro' => 'Il tuo spazio <strong>:space</strong> ha utilizzato il <strong>:percentage%</strong> della sua quota mensile di :metric.',
        'detail' => 'Hai utilizzato :used su :limit. Non è stato bloccato nulla: è solo un avviso per permetterti di agire prima di raggiungere il limite.',
        'action' => 'Controlla utilizzo e piani',
        'outro' => 'Valuta l\'upgrade del tuo piano se prevedi che questo utilizzo continui.',
    ],
    'usageExceeded' => [
        'subject' => ':space ha superato il limite di :metric',
        'intro' => 'Il tuo spazio <strong>:space</strong> ha utilizzato il <strong>:percentage%</strong> della sua quota mensile di :metric.',
        'detail' => 'Hai utilizzato :used su :limit. Per ora il servizio continua senza interruzioni, ma ti invitiamo a passare a un piano adatto al tuo utilizzo.',
        'action' => 'Passa a un piano superiore',
        'outro' => 'Un superamento prolungato potrebbe richiedere il passaggio a un piano superiore.',
    ],
    'usageMetrics' => [
        'storage' => 'spazio di archiviazione',
        'traffic' => 'traffico',
        'requests' => 'richieste API',
        'ai' => 'crediti IA',
    ],
];
