<?php

namespace App\Jobs\User;

use App\Events\User\UserDeleted;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Hash;

class DeleteUser implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public User $user
    )
    {
    }

    public function handle(): void
    {
        $email = $this->user->email;
        $this->anonymizeUser($this->user);
        $this->user->delete();

        event(new UserDeleted($email));
    }

    protected function anonymizeUser($user)
    {
        $user->forceFill([
            'firstname' => 'Anonymous',
            'lastname' => 'User',
            'email' => Hash::make($user->email)
        ]);
        $this->user->save();

        return $this;
    }

    protected function anonymizeFields(Model $model, array $fields)
    {
        foreach ($fields as $key => $value) {
            if (is_callable($value)) {
                $model->$key = $value($model->$key);
            } else {
                $model->$key = $value;
            }
        }
    }
}
