<?php

namespace App\Listeners\User;

use App\Events\User\UserDeleted;
use App\Notifications\User\UserDeletedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class NotifyUserDeleted implements ShouldQueue
{
    public function handle(UserDeleted $event): void
    {
        \Notification::route('mail', $event->email)
            ->notify(new UserDeletedNotification());
    }
}
