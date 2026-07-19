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
        'subject' => ':space zbliża się do limitu :metric',
        'intro' => 'Twoja przestrzeń <strong>:space</strong> wykorzystała <strong>:percentage%</strong> miesięcznego limitu :metric.',
        'detail' => 'Wykorzystano :used z :limit. Nic nie zostało zablokowane — to tylko ostrzeżenie, dzięki któremu możesz zareagować, zanim limit zostanie osiągnięty.',
        'action' => 'Sprawdź użycie i plany',
        'outro' => 'Rozważ przejście na wyższy plan, jeśli spodziewasz się, że takie zużycie się utrzyma.',
    ],
    'usageExceeded' => [
        'subject' => ':space przekroczył limit :metric',
        'intro' => 'Twoja przestrzeń <strong>:space</strong> wykorzystała <strong>:percentage%</strong> miesięcznego limitu :metric.',
        'detail' => 'Wykorzystano :used z :limit. Twoja usługa działa na razie bez zakłóceń, ale prosimy o przejście na plan odpowiadający Twojemu zużyciu.',
        'action' => 'Przejdź na wyższy plan',
        'outro' => 'Utrzymujące się przekraczanie limitu może wymagać przejścia na wyższy plan.',
    ],
    'usageMetrics' => [
        'storage' => 'przestrzeni dyskowej',
        'traffic' => 'transferu',
        'ai' => 'kredytów AI',
    ],
    'billingIntervals' => [
        'month' => 'miesiąc',
        'year' => 'rok',
    ],
    'paymentRequested' => [
        'subject' => 'Prośba o płatność dla :space',
        'intro' => '<strong>:requester</strong> prosi Cię o przejęcie subskrypcji przestrzeni <strong>:space</strong>.',
        'detail' => 'Proponowany plan: :plan za :price € / :interval. Staniesz się właścicielem rozliczeń i będziesz otrzymywać wszystkie faktury.',
        'action' => 'Sprawdź i zapłać',
        'outro' => 'Na stronie subskrypcji możesz wybrać inny plan, jeśli ten nie pasuje.',
        'inviteMessage' => ':requester prosi Cię o przejęcie subskrypcji „:space” (plan: :plan). Po dołączeniu otwórz ustawienia subskrypcji przestrzeni, aby dokończyć płatność.',
    ],
];
