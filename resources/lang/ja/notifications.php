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
        'subject' => ':space が:metricの上限に近づいています',
        'intro' => 'スペース <strong>:space</strong> は、月間の:metric枠のうち <strong>:percentage%</strong> を使用しました。',
        'detail' => ':limit のうち :used を使用しています。現時点で制限はかかっていません。上限に達する前に対応いただけるよう、事前にお知らせしています。',
        'action' => '使用状況とプランを確認',
        'outro' => 'この使用量が続く見込みの場合は、プランのアップグレードをご検討ください。',
    ],
    'usageExceeded' => [
        'subject' => ':space が:metricの上限を超過しました',
        'intro' => 'スペース <strong>:space</strong> は、月間の:metric枠のうち <strong>:percentage%</strong> を使用しました。',
        'detail' => ':limit のうち :used を使用しています。現時点ではサービスは中断なくご利用いただけますが、ご利用状況に合ったプランへのアップグレードをお願いいたします。',
        'action' => 'プランをアップグレード',
        'outro' => '超過が継続する場合は、上位プランへの移行が必要になることがあります。',
    ],
    'usageMetrics' => [
        'storage' => 'ストレージ',
        'traffic' => 'トラフィック',
        'requests' => 'APIリクエスト',
        'ai' => 'AIクレジット',
    ],
];
