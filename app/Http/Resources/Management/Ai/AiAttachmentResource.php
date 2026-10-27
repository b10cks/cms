<?php

namespace App\Http\Resources\Management\Ai;

use App\Models\Management\AiAttachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `url` is the download route, not the storage path.
 *
 * @mixin AiAttachment
 */
class AiAttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'kind' => $this->kind,
            'url' => route('mgmt.spaces.ai.attachments.show', [$this->space_id, $this->id], false),
        ];
    }
}
