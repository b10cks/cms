<?php

namespace App\Models\Management;

/**
 * A heading-delimited section of a reference revision, searched by `search_knowledge`.
 *
 * @property int $id
 * @property string $revision_id
 * @property string $heading_path
 * @property string $text
 * @property int $position
 */
class AiKnowledgeChunk extends GlobalModel
{
    public $timestamps = false;

    protected $fillable = ['revision_id', 'heading_path', 'text', 'position'];

    protected $casts = ['position' => 'integer'];
}
