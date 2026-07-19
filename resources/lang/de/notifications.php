<?php

return [
    'salutation' => "Möge dein Content fließen und nie im Block-Editor hängen bleiben,\ndas b10cks Team",
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
    'passwordReset' => [
        'subject' => 'Passwort zurücksetzen',
        'intro' => 'Du erhältst diese E-Mail, weil wir eine Anfrage zum Zurücksetzen des Passworts für dein Konto erhalten haben.',
        'action' => 'Passwort zurücksetzen',
        'expireNote' => 'Dieser Link zum Zurücksetzen des Passworts läuft in :count Minuten ab.',
        'note' => 'Falls du keine Zurücksetzung deines Passworts angefordert hast, ist keine weitere Aktion erforderlich.',
    ],
    'passwordChanged' => [
        'subject' => 'Dein Passwort wurde geändert',
        'intro' => 'Das Passwort für dein Konto wurde am <strong>:date</strong> geändert.<br><br><strong>Browser:</strong> :browser<br><strong>IP-Adresse:</strong> :ip',
        'note' => 'Falls du diese Änderung nicht vorgenommen hast, solltest du dein Passwort sofort erneut ändern: <a href=":resetUrl">Passwort zurücksetzen</a>',
    ],
    'usageWarning' => [
        'subject' => ':space nähert sich dem Limit für :metric',
        'intro' => 'Dein Space <strong>:space</strong> hat <strong>:percentage%</strong> seines monatlichen Kontingents für :metric verbraucht.',
        'detail' => ':used von :limit verbraucht. Es ist nichts blockiert — dies ist nur ein Hinweis, damit du handeln kannst, bevor das Limit erreicht ist.',
        'action' => 'Nutzung & Pläne ansehen',
        'outro' => 'Erwäge ein Upgrade deines Plans, falls du davon ausgehst, dass diese Nutzung anhält.',
    ],
    'usageExceeded' => [
        'subject' => ':space hat das Limit für :metric überschritten',
        'intro' => 'Dein Space <strong>:space</strong> hat <strong>:percentage%</strong> seines monatlichen Kontingents für :metric verbraucht.',
        'detail' => ':used von :limit verbraucht. Dein Service läuft vorerst uneingeschränkt weiter, aber bitte wechsle zu einem Plan, der zu deiner Nutzung passt.',
        'action' => 'Plan upgraden',
        'outro' => 'Bei dauerhafter Überschreitung kann ein Wechsel zu einem höheren Plan erforderlich sein.',
    ],
    'usageMetrics' => [
        'storage' => 'Speicherplatz',
        'traffic' => 'Traffic',
        'ai' => 'AI-Credits',
    ],
    'billingIntervals' => [
        'month' => 'Monat',
        'year' => 'Jahr',
    ],
    'paymentRequested' => [
        'subject' => 'Zahlungsanfrage für :space',
        'intro' => '<strong>:requester</strong> bittet dich, das Abonnement für den Space <strong>:space</strong> zu übernehmen.',
        'detail' => 'Vorgeschlagener Plan: :plan für €:price / :interval. Du wirst Rechnungsempfänger und erhältst alle Rechnungen.',
        'action' => 'Prüfen & bezahlen',
        'outro' => 'Auf der Abonnement-Seite kannst du auch einen anderen Plan wählen, falls dieser nicht passt.',
        'inviteMessage' => ':requester bittet dich, das Abonnement für „:space“ zu übernehmen (Plan: :plan). Öffne nach dem Beitritt die Abonnement-Einstellungen des Spaces, um die Zahlung abzuschließen.',
    ],
];
