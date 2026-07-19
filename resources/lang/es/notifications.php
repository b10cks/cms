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
        'ai' => 'créditos de IA',
    ],
    'billingIntervals' => [
        'month' => 'mes',
        'year' => 'año',
    ],
    'paymentRequested' => [
        'subject' => 'Pago solicitado para :space',
        'intro' => '<strong>:requester</strong> te pide que te hagas cargo de la suscripción del espacio <strong>:space</strong>.',
        'detail' => 'Plan propuesto: :plan por :price € / :interval. Pasarás a ser el titular de la facturación y recibirás todas las facturas.',
        'action' => 'Revisar y pagar',
        'outro' => 'Puedes elegir otro plan en la página de suscripción si este no encaja.',
        'inviteMessage' => ':requester te pide que te hagas cargo de la suscripción de «:space» (plan: :plan). Tras unirte, abre los ajustes de suscripción del espacio para completar el pago.',
    ],
];
