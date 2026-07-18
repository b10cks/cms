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
        'subject' => 'A(z) :space tér közeledik a(z) :metric limitjéhez',
        'intro' => 'A(z) <strong>:space</strong> tered felhasználta a havi :metric keret <strong>:percentage%</strong>-át.',
        'detail' => ':used felhasználva a :limit keretből. Semmi sincs letiltva — ez csak egy figyelmeztetés, hogy még a limit elérése előtt lépni tudj.',
        'action' => 'Használat és csomagok megtekintése',
        'outro' => 'Érdemes magasabb csomagra váltanod, ha arra számítasz, hogy ez a használat folytatódik.',
    ],
    'usageExceeded' => [
        'subject' => 'A(z) :space túllépte a(z) :metric limitet',
        'intro' => 'A(z) <strong>:space</strong> tered felhasználta a havi :metric keret <strong>:percentage%</strong>-át.',
        'detail' => ':used felhasználva a :limit keretből. A szolgáltatás egyelőre zavartalanul működik, de kérjük, válts a használatodhoz illő csomagra.',
        'action' => 'Csomag frissítése',
        'outro' => 'Tartós túllépés esetén magasabb csomagra lehet szükség.',
    ],
    'usageMetrics' => [
        'storage' => 'tárhely',
        'traffic' => 'adatforgalom',
        'requests' => 'API-kérés',
        'ai' => 'AI-kredit',
    ],
];
