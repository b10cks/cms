<?php

namespace App\Models\Management;

use Illuminate\Database\Eloquent\Concerns\HasUlids;

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
 * @property string $url
 */
class AiAttachment extends GlobalModel
{
    use HasUlids;

    protected $fillable = ['space_id', 'user_id', 'message_id', 'name', 'mime_type', 'size', 'url'];

    protected $casts = ['size' => 'integer'];
}
