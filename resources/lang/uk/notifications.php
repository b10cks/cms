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
        'subject' => ':space наближається до ліміту :metric',
        'intro' => 'Ваш простір <strong>:space</strong> використав <strong>:percentage%</strong> місячної квоти :metric.',
        'detail' => 'Використано :used із :limit. Нічого не заблоковано — це лише попередження, щоб ви могли вжити заходів до досягнення ліміту.',
        'action' => 'Переглянути використання та тарифи',
        'outro' => 'Якщо ви очікуєте, що таке використання триватиме, розгляньте перехід на вищий тариф.',
    ],
    'usageExceeded' => [
        'subject' => ':space перевищив ліміт :metric',
        'intro' => 'Ваш простір <strong>:space</strong> використав <strong>:percentage%</strong> місячної квоти :metric.',
        'detail' => 'Використано :used із :limit. Наразі сервіс працює без обмежень, але, будь ласка, перейдіть на тариф, що відповідає вашому використанню.',
        'action' => 'Підвищити тариф',
        'outro' => 'Постійне перевищення ліміту може вимагати переходу на вищий тариф.',
    ],
    'usageMetrics' => [
        'storage' => 'сховища',
        'traffic' => 'трафіку',
        'requests' => 'API-запитів',
        'ai' => 'AI-кредитів',
    ],
];
