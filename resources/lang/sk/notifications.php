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
        'subject' => ':space sa blíži k svojmu limitu pre :metric',
        'intro' => 'Váš priestor <strong>:space</strong> využil <strong>:percentage%</strong> svojej mesačnej kvóty pre :metric.',
        'detail' => 'Využité :used z :limit. Nič nie je zablokované — ide len o upozornenie, aby ste mohli konať skôr, než sa limit dosiahne.',
        'action' => 'Skontrolovať využitie a plány',
        'outro' => 'Ak očakávate, že toto využitie bude pokračovať, zvážte prechod na vyšší plán.',
    ],
    'usageExceeded' => [
        'subject' => ':space prekročil svoj limit pre :metric',
        'intro' => 'Váš priestor <strong>:space</strong> využil <strong>:percentage%</strong> svojej mesačnej kvóty pre :metric.',
        'detail' => 'Využité :used z :limit. Vaša služba zatiaľ beží bez obmedzení, ale prejdite prosím na plán, ktorý zodpovedá vášmu využitiu.',
        'action' => 'Prejsť na vyšší plán',
        'outro' => 'Dlhodobé prekračovanie limitu môže vyžadovať prechod na vyšší plán.',
    ],
    'usageMetrics' => [
        'storage' => 'úložisko',
        'traffic' => 'prenos dát',
        'requests' => 'API požiadavky',
        'ai' => 'AI kredity',
    ],
];
