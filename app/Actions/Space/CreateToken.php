<?php

namespace App\Actions\Space;

use App\Models\Management\Token;
use App\Models\Management\Space;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;

class CreateToken
{
    public function execute(array $data, Space $space, Authenticatable|User|null $owner): array
    {
        $token = new Token();
        $token->fill($data);
        $token->space_id = $space->id;

        // Published-content reads only unless the caller grants more; draft
        // access needs an explicit `preview` ability.
        if (empty($data['abilities'])) {
            $token->abilities = ['*:read'];
        }

        $plainTextToken = 'blx_' . Str::random(24);
        $token->token = $plainTextToken;

        abort_unless($token->save(), 500, 'Failed to create token');

        return ['token' => $token, 'plain_text_token' => $plainTextToken];
    }
}
