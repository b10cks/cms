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
    'verifyEmail' => [
        'subject' => 'Verify your email address',
        'greeting' => 'Hello :name,',
        'intro' => 'Please click the button below to verify your email address.',
        'action' => 'Verify Email Address',
        'outro' => 'If you did not create an account, no further action is required.',
    ],
    'twoFactorEnabled' => [
        'subject' => 'Two-factor authentication enabled',
        'greeting' => 'Hello :name,',
        'intro' => 'Two-factor authentication has been enabled for your account.',
        'outro' => 'If you did not enable two-factor authentication, please contact support immediately.',
    ],
    'twoFactorDisabled' => [
        'subject' => 'Two-factor authentication disabled',
        'greeting' => 'Hello :name,',
        'intro' => 'Two-factor authentication has been disabled for your account.',
        'outro' => 'If you did not disable two-factor authentication, please contact support immediately.',
    ],
    'twoFactorBackupCodesRegenerated' => [
        'subject' => 'Two-factor authentication backup codes regenerated',
        'greeting' => 'Hello :name,',
        'intro' => 'Your two-factor authentication backup codes have been regenerated.',
        'outro' => 'If you did not regenerate your backup codes, please contact support immediately.',
    ],
    'greeting' => 'Hello there,',
    'footerCopyright' => 'Coder\'s Cantina. All rights reserved.',
    'footer' => 'b10cks is an open-source headless CMS with modular blocks, no paywalls, and simple usage-based pricing. All features, all the time. Visit [www.b10cks.com](https://www.b10cks.com) to learn more.',
    'footerImprint' => 'You receive this notification because of your account at [b10cks.com](https://app.b10cks.com). Operated by Coder\'s Cantina e.U., 1020 Vienna, Austria.',
    'subCopy' => 'If you cannot click the button, copy and paste the URL below into your browser.',
    'inviterFallback' => 'a collaborator',
    'teamFallback' => 'Team',
];
