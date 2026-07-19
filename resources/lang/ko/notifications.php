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
        'subject' => ':space 스페이스가 :metric 한도에 가까워지고 있습니다',
        'intro' => '<strong>:space</strong> 스페이스가 월간 :metric 허용량의 <strong>:percentage%</strong>를 사용했습니다.',
        'detail' => ':limit 중 :used를 사용했습니다. 아직 차단된 것은 없으며, 한도에 도달하기 전에 조치하실 수 있도록 미리 알려드리는 것입니다.',
        'action' => '사용량 및 플랜 확인',
        'outro' => '이러한 사용량이 계속될 것으로 예상되면 플랜 업그레이드를 고려해 주세요.',
    ],
    'usageExceeded' => [
        'subject' => ':space 스페이스가 :metric 한도를 초과했습니다',
        'intro' => '<strong>:space</strong> 스페이스가 월간 :metric 허용량의 <strong>:percentage%</strong>를 사용했습니다.',
        'detail' => ':limit 중 :used를 사용했습니다. 현재로서는 서비스가 중단 없이 계속 제공되지만, 사용량에 맞는 플랜으로 업그레이드해 주시기 바랍니다.',
        'action' => '플랜 업그레이드',
        'outro' => '초과 사용이 지속되면 상위 플랜으로 전환해야 할 수 있습니다.',
    ],
    'usageMetrics' => [
        'storage' => '스토리지',
        'traffic' => '트래픽',
        'ai' => 'AI 크레딧',
    ],
    'billingIntervals' => [
        'month' => '월',
        'year' => '년',
    ],
    'paymentRequested' => [
        'subject' => ':space에 대한 결제 요청',
        'intro' => '<strong>:requester</strong> 님이 <strong>:space</strong> 스페이스의 구독을 인수해 달라고 요청했습니다.',
        'detail' => '제안된 플랜: :plan (€:price / :interval). 회원님이 결제 담당자가 되어 모든 청구서를 받게 됩니다.',
        'action' => '확인 및 결제',
        'outro' => '이 플랜이 맞지 않으면 구독 페이지에서 다른 플랜을 선택할 수 있습니다.',
        'inviteMessage' => ':requester 님이 ":space"의 구독 인수를 요청했습니다 (플랜: :plan). 참여 후 스페이스의 구독 설정을 열어 결제를 완료해 주세요.',
    ],
];
