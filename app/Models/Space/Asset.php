<?php

namespace App\Models\Space;

use App\Models\Management\Storage;
use App\Models\Traits\HasPurifiedAttributes;
use CodersCantina\Filter\Filterable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 *
 *
 * @property string $id
 * @property string $filename
 * @property string $extension
 * @property string $mime_type
 * @property string|null $path
 * @property string $storage_id
 * @property string|null $folder_id
 * @property int $size
 * @property array<array-key, mixed>|null $metadata
 * @property array<array-key, mixed>|null $data
 * @property array<array-key, mixed>|null $tags
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Models\Space\AssetFolder|null $folder
 * @property-read string $full_path
 * @property-read Storage|null $storage
 * @method static \Database\Factories\Space\AssetFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Asset filter(\CodersCantina\Filter\Filter $filter)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Asset newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Asset newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Asset onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Asset query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Asset whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Asset whereData($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Asset whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Asset whereExtension($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Asset whereFilename($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Asset whereFolderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Asset whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Asset whereMetadata($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Asset whereMimeType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Asset wherePath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Asset whereSize($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Asset whereStorageId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Asset whereTags($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Asset whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Asset withTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Asset withoutTrashed()
 * @mixin \Eloquent
 */
class Asset extends SpaceModel
{
    use Filterable;
    use HasFactory;
    use HasPurifiedAttributes;
    use HasUlids;
    use SoftDeletes;

    protected $table = 'assets';

    protected $fillable = [
        'filename',
        'extension',
        'mime_type',
        'path',
        'storage_id',
        'folder_id',
        'size',
        'metadata',
        'data',
        'tags',
    ];

    protected $casts = [
        'metadata' => 'array',
        'data' => 'array',
        'tags' => 'array',
    ];

    protected function filename(): Attribute
    {
        return $this->makePurifiedAttribute('removeAll');
    }

    public function storage(): BelongsTo
    {
        return $this->belongsTo(Storage::class, 'storage_id', 'id');
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(AssetFolder::class, 'folder_id', 'id');
    }

    public function getFullPathAttribute(): string
    {
        return $this->storage_id . '/' . $this->path;
    }

    public function getUrl(): ?string
    {
        return app(\App\Services\Storage\AssetService::class)->getAssetUrl($this);
    }
}
