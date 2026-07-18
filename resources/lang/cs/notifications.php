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
        'subject' => ':space se blíží svému limitu pro :metric',
        'intro' => 'Váš prostor <strong>:space</strong> využil <strong>:percentage%</strong> své měsíční kvóty pro :metric.',
        'detail' => 'Využito :used z :limit. Nic není blokováno — jde jen o upozornění, abyste mohli zareagovat dříve, než bude limit dosažen.',
        'action' => 'Zkontrolovat využití a tarify',
        'outro' => 'Pokud očekáváte, že toto využití bude pokračovat, zvažte přechod na vyšší tarif.',
    ],
    'usageExceeded' => [
        'subject' => ':space překročil svůj limit pro :metric',
        'intro' => 'Váš prostor <strong>:space</strong> využil <strong>:percentage%</strong> své měsíční kvóty pro :metric.',
        'detail' => 'Využito :used z :limit. Vaše služba zatím běží bez omezení, ale přejděte prosím na tarif, který odpovídá vašemu využití.',
        'action' => 'Přejít na vyšší tarif',
        'outro' => 'Dlouhodobé překračování limitu může vyžadovat přechod na vyšší tarif.',
    ],
    'usageMetrics' => [
        'storage' => 'úložiště',
        'traffic' => 'přenos dat',
        'requests' => 'požadavky API',
        'ai' => 'AI kredity',
    ],
];
