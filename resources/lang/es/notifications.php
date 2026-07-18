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
        'subject' => ':space se está acercando a su límite de :metric',
        'intro' => 'Tu espacio <strong>:space</strong> ha utilizado el <strong>:percentage%</strong> de su cuota mensual de :metric.',
        'detail' => 'Has utilizado :used de :limit. No hay nada bloqueado: esto es solo un aviso para que puedas actuar antes de alcanzar el límite.',
        'action' => 'Revisar uso y planes',
        'outro' => 'Considera mejorar tu plan si prevés que este uso continúe.',
    ],
    'usageExceeded' => [
        'subject' => ':space ha superado su límite de :metric',
        'intro' => 'Tu espacio <strong>:space</strong> ha utilizado el <strong>:percentage%</strong> de su cuota mensual de :metric.',
        'detail' => 'Has utilizado :used de :limit. Tu servicio continúa sin interrupciones por ahora, pero te recomendamos cambiar a un plan que se ajuste a tu uso.',
        'action' => 'Mejorar plan',
        'outro' => 'Un uso excesivo continuado puede requerir pasar a un plan superior.',
    ],
    'usageMetrics' => [
        'storage' => 'almacenamiento',
        'traffic' => 'tráfico',
        'requests' => 'solicitudes de API',
        'ai' => 'créditos de IA',
    ],
];
