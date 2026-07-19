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
        'subject' => ':space se približava svom ograničenju za :metric',
        'intro' => 'Vaš prostor <strong>:space</strong> iskoristio je <strong>:percentage%</strong> svoje mjesečne kvote za :metric.',
        'detail' => 'Iskorišteno :used od :limit. Ništa nije blokirano — ovo je samo upozorenje kako biste mogli reagirati prije nego što se dosegne ograničenje.',
        'action' => 'Pregledajte potrošnju i planove',
        'outro' => 'Razmislite o nadogradnji plana ako očekujete da će se ovakva potrošnja nastaviti.',
    ],
    'usageExceeded' => [
        'subject' => ':space je premašio svoje ograničenje za :metric',
        'intro' => 'Vaš prostor <strong>:space</strong> iskoristio je <strong>:percentage%</strong> svoje mjesečne kvote za :metric.',
        'detail' => 'Iskorišteno :used od :limit. Vaša usluga zasad radi bez prekida, ali molimo nadogradite na plan koji odgovara vašoj potrošnji.',
        'action' => 'Nadogradi plan',
        'outro' => 'Trajno prekoračenje može zahtijevati prelazak na viši plan.',
    ],
    'usageMetrics' => [
        'storage' => 'pohranu',
        'traffic' => 'promet',
        'ai' => 'AI kredite',
    ],
    'billingIntervals' => [
        'month' => 'mjesec',
        'year' => 'godina',
    ],
    'paymentRequested' => [
        'subject' => 'Zatražena uplata za :space',
        'intro' => '<strong>:requester</strong> traži da preuzmete pretplatu za prostor <strong>:space</strong>.',
        'detail' => 'Predloženi plan: :plan za :price € / :interval. Postat ćete vlasnik naplate i primati sve račune.',
        'action' => 'Pregledaj i plati',
        'outro' => 'Na stranici pretplate možete odabrati drugi plan ako vam ovaj ne odgovara.',
        'inviteMessage' => ':requester traži da preuzmete pretplatu za „:space” (plan: :plan). Nakon pridruživanja otvorite postavke pretplate prostora kako biste dovršili plaćanje.',
    ],
];
