<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\User\OneTimeTokenNotification;
use Illuminate\Http\Request;

class CreateOneTimeTokenController extends Controller
{
    public function __invoke(Request $request)
    {
        $request->validate(['email' => 'required|string|email:rfc,filter']);
        $user = User::where('email', $request->email)->first();
        $tokens = $this->createToken($user);

        $user->notify(new OneTimeTokenNotification(end($tokens)));

        return response(null, 204);
    }

    protected function createToken(User $user): array
    {
        $tokens = \Cache::remember('user:' . $user->id . ':one-time-token', 120, function () use ($user) {
            $result = \Cache::get('user:' . $user->id . ':one-time-token', []);

            return array_merge($result, [random_int(100000, 999999)]);
        });

        return $tokens;
    }
}
