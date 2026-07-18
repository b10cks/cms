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
        'subject' => ':space närmar sig sin gräns för :metric',
        'intro' => 'Ditt space <strong>:space</strong> har använt <strong>:percentage%</strong> av sin månatliga kvot för :metric.',
        'detail' => ':used av :limit använt. Inget är blockerat — detta är bara en förvarning så att du kan agera innan gränsen nås.',
        'action' => 'Granska användning & planer',
        'outro' => 'Överväg att uppgradera din plan om du förväntar dig att denna användning fortsätter.',
    ],
    'usageExceeded' => [
        'subject' => ':space har överskridit sin gräns för :metric',
        'intro' => 'Ditt space <strong>:space</strong> har använt <strong>:percentage%</strong> av sin månatliga kvot för :metric.',
        'detail' => ':used av :limit använt. Din tjänst fortsätter utan avbrott tills vidare, men uppgradera gärna till en plan som passar din användning.',
        'action' => 'Uppgradera plan',
        'outro' => 'Långvarig överanvändning kan kräva ett byte till en högre plan.',
    ],
    'usageMetrics' => [
        'storage' => 'lagringsutrymme',
        'traffic' => 'trafik',
        'requests' => 'API-förfrågningar',
        'ai' => 'AI-krediter',
    ],
];
