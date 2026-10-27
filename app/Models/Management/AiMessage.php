<?php

namespace App\Models\Management;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $conversation_id
 * @property string|null $run_id
 * @property string $role
 * @property string $content
 */
class AiMessage extends GlobalModel
{
    use HasUlids;

    protected $fillable = ['conversation_id', 'run_id', 'role', 'content'];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(AiAttachment::class, 'message_id');
    }
}
