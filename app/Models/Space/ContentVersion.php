<?php

namespace App\Models\Space;

use App\Casts\Content\ContentCast;
use App\Models\User;
use App\Services\Content\AssetHandler;
use App\Services\Content\LinkHandler;
use CodersCantina\Filter\Filterable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Staudenmeir\EloquentJsonRelations\HasJsonRelationships;
use Staudenmeir\EloquentJsonRelations\Relations\BelongsToJson;

/**
 * 
 *
 * @property string $id
 * @property string|null $message
 * @property \App\Models\Space\Content|null $content
 * @property array<array-key, mixed>|null $asset_ids
 * @property array<array-key, mixed>|null $relation_ids
 * @property array<array-key, mixed>|null $link_ids
 * @property string $content_id
 * @property string|null $parent_id
 * @property string|null $release_id
 * @property string|null $created_by_id
 * @property \Illuminate\Support\Carbon|null $published_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ContentVersion> $children
 * @property-read int|null $children_count
 * @property-read User|null $createdBy
 * @property-read ContentVersion|null $parent
 * @property-read \App\Models\Space\Release|null $release
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Space\Asset[] $assets
 * @property-read int|null $assets_count
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Space\Content[] $links
 * @property-read int|null $links_count
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Space\Content[] $relations
 * @property-read int|null $relations_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContentVersion filter(\CodersCantina\Filter\Filter $filter)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContentVersion newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContentVersion newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContentVersion query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContentVersion whereAssetIds($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContentVersion whereContent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContentVersion whereContentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContentVersion whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContentVersion whereCreatedById($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContentVersion whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContentVersion whereLinkIds($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContentVersion whereMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContentVersion whereParentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContentVersion wherePublishedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContentVersion whereRelationIds($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContentVersion whereReleaseId($value)
 * @mixin \Eloquent
 */
class ContentVersion extends SpaceModel
{
    use HasJsonRelationships;
    use HasUlids;
    use Filterable;

    public $timestamps = false;

    protected $fillable = [
        'content',
        'message',
    ];

    protected $casts = [
        'asset_ids' => 'array',
        'content' => ContentCast::class,
        'created_at' => 'datetime',
        'link_ids' => 'array',
        'published_at' => 'datetime',
        'relation_ids' => 'array',
    ];

    public static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->created_at = $model->freshTimestamp();
        });

        static::saving(function ($model) {
            $model->prepareLinksForSave();
            $model->prepareAssetsForSave();
        });
    }

    protected function prepareLinksForSave(): void
    {
        $extractor = app(LinkHandler::class);
        $this->link_ids = $extractor->extractContentLinks($this->content);
    }

    protected function prepareAssetsForSave(): void
    {
        $extractor = app(AssetHandler::class);
        $this->asset_ids = $extractor->extractContentAssets($this->content);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id', 'id');
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class, 'content_id', 'id');
    }

    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class, 'release_id', 'id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(ContentVersion::class, 'parent_id', 'id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(ContentVersion::class, 'parent_id', 'id');
    }

    public function assets(): BelongsToJson
    {
        return $this->belongsToJson(Asset::class, 'asset_ids');
    }

    public function links(): BelongsToJson
    {
        return $this->belongsToJson(Content::class, 'link_ids');
    }

    public function relations(): BelongsToJson
    {
        return $this->belongsToJson(Content::class, 'relation_ids');
    }
}
