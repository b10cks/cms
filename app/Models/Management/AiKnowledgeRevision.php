<?php

namespace App\Models\Management;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $document_id
 * @property int $revision
 * @property string $markdown
 * @property string $hash
 * @property string|null $published_by
 */
class AiKnowledgeRevision extends GlobalModel
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = ['document_id', 'revision', 'markdown', 'hash', 'published_by'];

    protected $casts = ['revision' => 'integer'];

    public function document(): BelongsTo
    {
        return $this->belongsTo(AiKnowledgeDocument::class, 'document_id');
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(AiKnowledgeChunk::class, 'revision_id')->orderBy('position');
    }
}
