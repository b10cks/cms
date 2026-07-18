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
        'subject' => ':space приближается к лимиту :metric',
        'intro' => 'Ваше пространство <strong>:space</strong> использовало <strong>:percentage%</strong> месячной квоты :metric.',
        'detail' => 'Использовано :used из :limit. Ничего не заблокировано — это лишь предупреждение, чтобы вы могли принять меры до достижения лимита.',
        'action' => 'Просмотреть использование и тарифы',
        'outro' => 'Если вы ожидаете, что такое использование продолжится, рассмотрите переход на более высокий тариф.',
    ],
    'usageExceeded' => [
        'subject' => ':space превысил лимит :metric',
        'intro' => 'Ваше пространство <strong>:space</strong> использовало <strong>:percentage%</strong> месячной квоты :metric.',
        'detail' => 'Использовано :used из :limit. Пока сервис работает без ограничений, но, пожалуйста, перейдите на тариф, соответствующий вашему использованию.',
        'action' => 'Повысить тариф',
        'outro' => 'Постоянное превышение лимита может потребовать перехода на более высокий тариф.',
    ],
    'usageMetrics' => [
        'storage' => 'хранилища',
        'traffic' => 'трафика',
        'requests' => 'API-запросов',
        'ai' => 'AI-кредитов',
    ],
];
