<?php

namespace App\Listeners\User;

use App\Events\User\PasswordChanged;
use App\Notifications\User\PasswordChangedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class NotifyPasswordChanged implements ShouldQueue
{
    public function handle(PasswordChanged $event): void
    {
        $event->user
            ->notify(new PasswordChangedNotification($event->data));
    }
}
