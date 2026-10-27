<?php

namespace App\Models\Management;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file the user attached to a conversation message.
 *
 * @property string $id
 * @property string $space_id
 * @property string $user_id
 * @property string|null $message_id
 * @property string $name
 * @property string $mime_type
 * @property int $size
 * @property string $url storage path on the `transfers` disk
 * @property-read string $kind
 */
class AiAttachment extends GlobalModel
{
    use HasUlids;

    protected $fillable = ['space_id', 'user_id', 'message_id', 'name', 'mime_type', 'size', 'url'];

    public const KIND_IMAGE = 'image';

    public const KIND_DOCUMENT = 'document';

    protected $casts = ['size' => 'integer'];

    /** Images go to the model as image parts, documents as extracted text. */
    public function getKindAttribute(): string
    {
        return str_starts_with($this->mime_type, 'image/') ? self::KIND_IMAGE : self::KIND_DOCUMENT;
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(AiMessage::class, 'message_id');
    }
}
