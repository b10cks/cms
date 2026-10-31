<?php

return [
    'domains' => [
        /*
         * Mail providers anyone can sign up with. A team may not claim them,
         * because owning the DNS of gmail.com proves nothing about its users.
         */
        'public_email_providers' => [
            'gmail.com', 'googlemail.com',
            'outlook.com', 'outlook.de', 'hotmail.com', 'hotmail.de', 'live.com', 'msn.com',
            'yahoo.com', 'yahoo.de', 'ymail.com',
            'icloud.com', 'me.com', 'mac.com',
            'aol.com',
            'proton.me', 'protonmail.com', 'pm.me',
            'gmx.de', 'gmx.net', 'gmx.at', 'gmx.ch', 'web.de', 't-online.de', 'posteo.de', 'mailbox.org',
            'yandex.ru', 'yandex.com', 'mail.ru',
            'zoho.com', 'fastmail.com', 'hey.com', 'tutanota.com', 'tuta.io',
            'qq.com', '163.com',
        ],
    ],
];
