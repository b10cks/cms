<?php

namespace App\Models\Space;

use App\Database\HasManyFromArray;
use App\Database\HasManyFromArrayTrait;
use App\Events\Space\ContentDeleted;
use App\Events\Space\ContentUpdated;
use App\Jobs\Content\UpdateContentFullSlugsJob;
use App\Models\Traits\HasPurifiedAttributes;
use App\Models\Traits\SpaceAuditable;
use App\Services\Automation\Enums\TriggerType;
use App\Services\Content\ContentMenuCache;
use App\Services\Content\LocalizedContentSlugService;
use App\Services\Content\Serial\ContentSerialAssigner;
use App\Services\Slug\Slugger;
use App\Support\SpaceContext;
use CodersCantina\Filter\Filterable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property string $id
 * @property string|null $external_id
 * @property string $block_id
 * @property string|null $parent_id
 * @property int $position
 * @property string|null $name
 * @property string $slug
 * @property string $full_slug
 * @property string $language_iso
 * @property string|null $i18n_parent_id
 * @property string|null $content
 * @property ContentSettings|null $settings
 * @property string $current_version_id
 * @property string|null $published_version_id
 * @property string|null $searchable_content
 * @property Carbon|null $published_at
 * @property Carbon|null $first_published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Block|null $block
 * @property-read Collection<int, Content> $children
 * @property-read int|null $children_count
 * @property-read ContentVersion|null $current_version
 * @property-read Collection<int, Content> $i18n_children
 * @property-read int|null $i18n_children_count
 * @property-read Content|null $i18n_parent
 * @property-read Collection<int, Content> $i18n_siblings
 * @property-read int|null $i18n_siblings_count
 * @property-read Content|null $parent
 * @property-read ContentVersion|null $published_version
 * @property-read Collection<int, ContentVersion> $versions
 * @property-read int|null $versions_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Content filter(\CodersCantina\Filter\Filter $filter)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Content newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Content newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Content onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Content query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Content whereBlockId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Content whereContent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Content whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Content whereCurrentVersionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Content whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Content whereFirstPublishedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Content whereFullSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Content whereI18nParentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Content whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Content whereLanguageIso($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Content whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Content whereParentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Content wherePosition($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Content wherePublishedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Content wherePublishedVersionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Content whereSettings($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Content whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Content whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Content withTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Content withoutTrashed()
 *
 * @mixin \Eloquent
 */
class Content extends SpaceModel
{
    use Filterable;
    use HasFactory;
    use HasManyFromArrayTrait;
    use HasPurifiedAttributes;
    use HasUlids;
    use SoftDeletes;
    use SpaceAuditable;

    protected $table = 'contents';

    protected $fillable = [
        'external_id',
        'name',
        'slug',
        'language_iso',
        'description',
        'block_id',
        'parent_id',
        'position',
        'i18n_parent_id',
        'settings',
        'published_at',
        'searchable_content',
    ];

    protected $casts = [
        'settings' => ContentSettings::class,
        // No `slug` cast: the mutator below already wins over it, and the real
        // normalization happens in the `saving` hook where the language is known.
        'position' => 'integer',
        'published_at' => 'datetime',
        'first_published_at' => 'datetime',
    ];

    protected const array REDUCED_FIELDSET = ['id', 'name', 'slug', 'full_slug', 'language_iso', 'position', 'current_version_id', 'published_version_id', 'published_at', 'first_published_at', 'created_at', 'updated_at', 'i18n_parent_id'];

    /**
     * Every persisted column except the heavy `searchable_content` LONGTEXT, which
     * only the MySQL search driver needs yet otherwise rides along on every
     * unscoped delivery/resolution read. Use on those hot paths to keep the
     * LONGTEXT out of the row without losing any column resolution relies on.
     */
    public const array DELIVERY_FIELDSET = [
        'id', 'external_id', 'block_id', 'parent_id', 'position', 'name', 'slug',
        'full_slug', 'language_iso', 'i18n_parent_id', 'content', 'settings',
        'current_version_id', 'published_version_id', 'published_at',
        'first_published_at', 'created_at', 'updated_at', 'deleted_at',
    ];

    /**
     * @return array<int, string>
     */
    public static function deliveryColumns(string $prefix = ''): array
    {
        if ($prefix === '') {
            return self::DELIVERY_FIELDSET;
        }

        return array_map(static fn (string $column): string => $prefix.$column, self::DELIVERY_FIELDSET);
    }

    protected static function boot()
    {
        parent::boot();

        static::updated(function (Content $content) {
            $trigger = $content->publicationTrigger();

            if ($trigger !== null) {
                $content->dispatchAutomationTrigger($trigger);
            }
        });

        static::saving(function (Content $content) {
            // Before full_slug is composed from it, and with language_iso
            // populated, which is the whole reason it does not happen in the
            // mutator.
            $content->slug = app(Slugger::class)->forContent(
                (string) $content->getAttributeValue('slug'),
                $content->language_iso,
            );

            $slugService = app(LocalizedContentSlugService::class);
            $oldFullSlug = $slugService->updateFullSlug($content);
            if (! empty($oldFullSlug)) {
                $redirectSource = $slugService->formatRedirectSlug($oldFullSlug, $content->language_iso);
                $redirectTarget = $slugService->formatRedirectSlug($content->full_slug, $content->language_iso);
                $slugService->createRedirect($redirectSource, $redirectTarget);
            }
        });

        static::saved(function (Content $content) {
            $space = request('space') ?? SpaceContext::current();

            if ($content->isDirty('slug') || $content->isDirty('parent_id') || $content->isDirty('language_iso')) {
                UpdateContentFullSlugsJob::dispatch($content, $space);
            }

            self::scheduleContentMenuInvalidation();
            if ($space) {
                broadcast(new ContentUpdated($content, $space))->toOthers();
            }
        });

        static::softDeleted(function (Content $content) {
            $space = request('space') ?? SpaceContext::current();

            if ($space) {
                // Returns the entry's serial numbers to the pool when the space
                // reuses gaps; a no-op under the default `preserve`.
                app(ContentSerialAssigner::class)->onTrashed($space, $content);
            }

            self::scheduleContentMenuInvalidation();
            if ($space) {
                broadcast(new ContentDeleted($content, $space))->toOthers();
            }
        });

        static::restored(function (Content $content) {
            $space = request('space') ?? SpaceContext::current();

            if ($space) {
                app(ContentSerialAssigner::class)->restoreFor($space, $content);
            }

            self::scheduleContentMenuInvalidation();
        });

        static::forceDeleted(function (Content $content) {
            $space = request('space') ?? SpaceContext::current();

            // Same rule as trashing: under `reuse` the reservations return to
            // the pool; under `preserve` the rows stay behind on purpose, so a
            // purged entry's numbers remain burned forever. Covers hard deletes
            // that never went through the trash.
            if ($space) {
                app(ContentSerialAssigner::class)->onTrashed($space, $content);
            }
        });
    }

    protected static function scheduleContentMenuInvalidation(): void
    {
        $spaceId = request('space')?->id
            ?? (app()->bound('currentSpace') ? app('currentSpace')->id : null);

        if (! $spaceId) {
            return;
        }

        DB::afterCommit(static function () use ($spaceId): void {
            app(ContentMenuCache::class)->invalidate($spaceId);
        });
    }

    protected function name(): Attribute
    {
        return $this->makePurifiedAttribute('removeAll');
    }

    /**
     * Stored raw on purpose.
     *
     * Slugging here would run before `language_iso` is guaranteed to be set —
     * attribute order follows the payload — and folding "Über" to "uber" with
     * the English map is not something a later pass with the German map can
     * undo. The `saving` hook normalizes it once, when the language is known.
     */
    public function setSlugAttribute($value)
    {
        $this->attributes['slug'] = \is_string($value) ? trim($value) : $value;
    }

    public function block(): BelongsTo
    {
        return $this->belongsTo(Block::class, 'block_id', 'id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Content::class, 'parent_id', 'id')
            ->orderBy('position')
            ->orderBy('name')
            ->orderBy('id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Content::class, 'parent_id', 'id');
    }

    public function i18n_parent(): BelongsTo
    {
        return $this->belongsTo(Content::class, 'i18n_parent_id', 'id')
            ->leftJoin('content_versions', 'contents.published_version_id', '=', 'content_versions.id')
            ->select([...self::deliveryColumns('contents.'), 'content_versions.content']);
    }

    public function i18n_children(): HasMany
    {
        return $this->hasMany(Content::class, 'i18n_parent_id', 'id')
            ->select(self::REDUCED_FIELDSET);
    }

    public function i18n_siblings(): HasMany
    {
        return $this->hasMany(Content::class, 'i18n_parent_id', 'i18n_parent_id')
            ->where('i18n_parent_id', '!=', null)
            ->select(self::REDUCED_FIELDSET);
    }

    public function getContent(): array
    {
        if ($this->content) {
            return json_decode($this->content ?? '[]', true);
        }

        $this->loadMissing('published_version');

        return $this->published_version?->content ?? [];
    }

    public function getCurrentContent(): array
    {
        $this->loadMissing('current_version');

        return $this->current_version?->content ?? [];
    }

    public function setPublishedAt($date): void
    {
        $this->published_at = $date;
        if ($this->first_published_at === null) {
            $this->first_published_at = $date;
        }
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ContentVersion::class, 'content_id', 'id');
    }

    public function current_version(): BelongsTo
    {
        return $this->belongsTo(ContentVersion::class, 'current_version_id', 'id');
    }

    public function published_version(): BelongsTo
    {
        return $this->belongsTo(ContentVersion::class, 'published_version_id', 'id');
    }

    /**
     * Which publication automation, if any, the save that just happened
     * represents.
     *
     * `published_at` is live-since, not up-to-date: it stays put across an edit
     * and is merely restamped by the next publish. So only the first publish of
     * an entry — and a publish after an explicit unpublish — is a null → date
     * transition, and keying the trigger on that alone would fire once in an
     * entry's lifetime. What every publish does move is the version pointer.
     * Unpublishing remains the one thing that clears the column.
     */
    public function publicationTrigger(): ?TriggerType
    {
        $isLive = $this->getAttribute('published_at') !== null;
        $wasLive = $this->getOriginal('published_at') !== null;

        if ($isLive && (! $wasLive || $this->wasChanged('published_version_id'))) {
            return TriggerType::CONTENT_PUBLISHED;
        }

        return $wasLive && ! $isLive ? TriggerType::CONTENT_UNPUBLISHED : null;
    }

    /**
     * The delivery notion of "published".
     *
     * Both columns matter. `published_version_id` survives an unpublish, so an
     * entry that was published once and taken down again still points at a
     * version — checking only that column hands the old payload straight back
     * out. `published_at` is what publishing and unpublishing actually move.
     *
     * @param  Builder<Content>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->whereNotNull($query->qualifyColumn('published_at'))
            ->whereNotNull($query->qualifyColumn('published_version_id'));
    }

    public function assets(): HasManyFromArray
    {
        return $this->hasManyFromArray(Asset::class, 'asset_ids');
    }

    public function links(): HasManyFromArray
    {
        return $this->hasManyFromArray(Content::class, 'link_ids')
            ->select(self::deliveryColumns());
    }

    public function relations(): HasManyFromArray
    {
        return $this->hasManyFromArray(Content::class, 'relation_ids')
            ->select(self::deliveryColumns());
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'content_id', 'id');
    }
}
