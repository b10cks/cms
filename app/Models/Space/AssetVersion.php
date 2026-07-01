<?php

namespace App\Models\Space;

use App\Models\User;
use CodersCantina\Filter\Filterable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable snapshot of a previous physical file + metadata state for an
 * asset, created just before the file is replaced (or restored). Mirrors the
 * `content_versions` pattern: no `updated_at`, only `created_at` is set.
 *
 * @property string $id
 * @property string|null $external_id
 * @property string $asset_id
 * @property int $version_number
 * @property string $filename
 * @property string $extension
 * @property string $mime_type
 * @property string|null $path
 * @property int $size
 * @property string|null $checksum
 * @property array<array-key, mixed>|null $metadata
 * @property string|null $created_by_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property-read Asset|null $asset
 * @property-read User|null $createdBy
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AssetVersion filter(\CodersCantina\Filter\Filter $filter)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AssetVersion newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AssetVersion newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AssetVersion query()
 *
 * @mixin \Eloquent
 */
class AssetVersion extends SpaceModel
{
    use Filterable;
    use HasFactory;
    use HasUlids;

    public $timestamps = false;

    protected $table = 'asset_versions';

    protected $fillable = [
        'external_id',
        'asset_id',
        'version_number',
        'filename',
        'extension',
        'mime_type',
        'path',
        'size',
        'checksum',
        'metadata',
        'created_by_id',
    ];

    protected $casts = [
        'metadata' => 'array',
        'version_number' => 'integer',
        'size' => 'integer',
        'created_at' => 'datetime',
    ];

    public static function boot()
    {
        parent::boot();

        static::creating(function (self $model) {
            $model->created_at = $model->freshTimestamp();
        });
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id', 'id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id', 'id');
    }

    public function getFullPathAttribute(): ?string
    {
        if (! $this->path || ! $this->asset) {
            return null;
        }

        return $this->asset->storage_id . '/' . $this->path;
    }
}
