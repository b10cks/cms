<?php

namespace App\Models\Management;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A chat thread of one user on one surface. Runs started from it append their
 * prompt and answer as messages.
 *
 * @property string $id
 * @property string $space_id
 * @property string $user_id
 * @property string $surface
 * @property string|null $title
 */
class AiConversation extends GlobalModel
{
    use HasUlids;

    protected $fillable = ['space_id', 'user_id', 'surface', 'title'];

    public function messages(): HasMany
    {
        return $this->hasMany(AiMessage::class, 'conversation_id')->orderBy('created_at');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AiRun::class, 'conversation_id');
    }
}
