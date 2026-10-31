<?php

namespace App\Actions\Provider;

use App\Enums\InstanceAuditAction;
use App\Models\Management\Impersonation;
use App\Models\User;
use App\Services\Audit\InstanceAuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ends every browser session of a user. Bumping the session epoch kills
 * sessions on any driver (see AuthenticateSession); rotating the remember
 * token kills "remember me" cookies. Personal access tokens are standing
 * credentials and stay, except impersonation tokens, which are sessions.
 */
class SignOutEverywhere
{
    public function __construct(private readonly InstanceAuditService $audit) {}

    public function execute(User $actor, User $user, bool $audit = true): void
    {
        $user->increment('session_epoch', 1, ['remember_token' => Str::random(60)]);

        $user->tokens()->whereNotNull('impersonator_id')->delete();
        Impersonation::query()->active()->where('user_id', $user->id)->update(['ended_at' => now()]);

        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))
                ->table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->delete();
        }

        if ($audit) {
            $this->audit->record(InstanceAuditAction::UserSignedOut, $user, $actor, [
                'user_email' => $user->email,
            ]);
        }
    }
}
