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
        'subject' => ':space approche de sa limite de :metric',
        'intro' => 'Votre espace <strong>:space</strong> a utilisé <strong>:percentage%</strong> de son quota mensuel de :metric.',
        'detail' => ':used utilisés sur :limit. Rien n\'est bloqué — il s\'agit simplement d\'un avertissement pour que vous puissiez agir avant d\'atteindre la limite.',
        'action' => 'Consulter l\'utilisation et les offres',
        'outro' => 'Envisagez de passer à une offre supérieure si vous pensez que cette utilisation va se poursuivre.',
    ],
    'usageExceeded' => [
        'subject' => ':space a dépassé sa limite de :metric',
        'intro' => 'Votre espace <strong>:space</strong> a utilisé <strong>:percentage%</strong> de son quota mensuel de :metric.',
        'detail' => ':used utilisés sur :limit. Votre service continue de fonctionner normalement pour le moment, mais veuillez passer à une offre adaptée à votre utilisation.',
        'action' => 'Passer à une offre supérieure',
        'outro' => 'Un dépassement prolongé peut nécessiter le passage à une offre supérieure.',
    ],
    'usageMetrics' => [
        'storage' => 'stockage',
        'traffic' => 'trafic',
        'ai' => 'crédits IA',
    ],
    'billingIntervals' => [
        'month' => 'mois',
        'year' => 'an',
    ],
    'paymentRequested' => [
        'subject' => 'Paiement demandé pour :space',
        'intro' => '<strong>:requester</strong> vous demande de prendre en charge l\'abonnement de l\'espace <strong>:space</strong>.',
        'detail' => 'Plan proposé : :plan à :price € / :interval. Vous deviendrez le titulaire de la facturation et recevrez toutes les factures.',
        'action' => 'Vérifier et payer',
        'outro' => 'Vous pouvez choisir un autre plan sur la page d\'abonnement si celui-ci ne convient pas.',
        'inviteMessage' => ':requester vous demande de prendre en charge l\'abonnement de « :space » (plan : :plan). Après avoir rejoint l\'espace, ouvrez ses paramètres d\'abonnement pour finaliser le paiement.',
    ],
];
