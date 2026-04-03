<?php

namespace App\Services\Audit;

use App\Models\Space\Asset;
use App\Models\Space\AssetFolder;
use App\Models\Space\AssetTag;
use App\Models\Space\Block;
use App\Models\Space\BlockFolder;
use App\Models\Space\BlockTag;
use App\Models\Space\BlockTemplate;
use App\Models\Space\BlockVersion;
use App\Models\Space\Comment;
use App\Models\Space\CommentReaction;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Models\Space\DataEntry;
use App\Models\Space\DataSource;
use App\Models\Space\Redirect;
use App\Models\Space\Release;

class AuditSubjectRegistry
{
    private static array $registry = [];

    private static bool $initialized = false;

    private static function initialize(): void
    {
        if (self::$initialized) {
            return;
        }

        self::$registry = [
            Content::class => [
                'type' => 'content',
                'label' => fn ($m) => $m->name ?? $m->full_slug ?? $m->id,
            ],
            ContentVersion::class => [
                'type' => 'content_version',
                'label' => fn ($m) => 'Version of '.($m->content?->name ?? $m->content?->full_slug ?? $m->content_id),
            ],
            Block::class => [
                'type' => 'block',
                'label' => fn ($m) => $m->name ?? $m->id,
            ],
            BlockVersion::class => [
                'type' => 'block_version',
                'label' => fn ($m) => 'Version of '.($m->block?->name ?? $m->block_id),
            ],
            BlockTemplate::class => [
                'type' => 'block_template',
                'label' => fn ($m) => $m->name ?? $m->id,
            ],
            BlockFolder::class => [
                'type' => 'block_folder',
                'label' => fn ($m) => $m->name ?? $m->id,
            ],
            BlockTag::class => [
                'type' => 'block_tag',
                'label' => fn ($m) => $m->name ?? $m->id,
            ],
            Asset::class => [
                'type' => 'asset',
                'label' => fn ($m) => $m->filename ?? $m->id,
            ],
            AssetFolder::class => [
                'type' => 'asset_folder',
                'label' => fn ($m) => $m->name ?? $m->id,
            ],
            AssetTag::class => [
                'type' => 'asset_tag',
                'label' => fn ($m) => $m->name ?? $m->id,
            ],
            DataSource::class => [
                'type' => 'data_source',
                'label' => fn ($m) => $m->name ?? $m->id,
            ],
            DataEntry::class => [
                'type' => 'data_entry',
                'label' => fn ($m) => $m->key ?? $m->id,
            ],
            Redirect::class => [
                'type' => 'redirect',
                'label' => fn ($m) => $m->source ?? $m->id,
            ],
            Release::class => [
                'type' => 'release',
                'label' => fn ($m) => $m->name ?? $m->id,
            ],
            Comment::class => [
                'type' => 'comment',
                'label' => fn ($m) => mb_substr(strip_tags((string) ($m->body ?? '')), 0, 80),
            ],
            CommentReaction::class => [
                'type' => 'comment_reaction',
                'label' => fn ($m) => $m->reaction ?? $m->id,
            ],
        ];

        self::$initialized = true;
    }

    public static function getType(object $model): ?string
    {
        self::initialize();

        return self::$registry[get_class($model)]['type'] ?? null;
    }

    public static function getLabel(object $model): string
    {
        self::initialize();

        $resolver = self::$registry[get_class($model)]['label'] ?? null;

        if ($resolver) {
            return (string) $resolver($model);
        }

        foreach (['name', 'title', 'filename', 'key', 'slug', 'source', 'email'] as $field) {
            if (! empty($model->{$field})) {
                return (string) $model->{$field};
            }
        }

        return (string) ($model->id ?? '');
    }

    /**
     * Return the frontend route name for a given referenced_type, or null if no canonical route exists.
     */
    public static function getRouteName(string $type): ?string
    {
        return match ($type) {
            'content' => 'space-content-contentId',
            'content_version' => 'space-content-contentId-versions',
            'block',
            'block_version',
            'block_template' => 'space-block',
            'data_source' => 'space-datasources-dataSourceId',
            'data_entry' => 'space-datasources-dataSourceId',
            'comment',
            'comment_reaction' => 'space-content-contentId',
            default => null,
        };
    }

    /**
     * Build route params for the item link. Returns null when the type has no canonical route.
     * Parent IDs for nested types must be stored in meta at write time.
     */
    public static function getRouteParams(string $type, string $referencedId, ?array $meta): ?array
    {
        return match ($type) {
            'content' => ['contentId' => $referencedId],
            'content_version' => isset($meta['content_id'])
                ? ['contentId' => $meta['content_id']]
                : null,
            'block' => ['block' => $referencedId],
            'block_version',
            'block_template' => isset($meta['block_id'])
                ? ['block' => $meta['block_id']]
                : null,
            'data_source' => ['dataSourceId' => $referencedId],
            'data_entry' => isset($meta['data_source_id'])
                ? ['dataSourceId' => $meta['data_source_id']]
                : null,
            'comment' => isset($meta['content_id'])
                ? ['contentId' => $meta['content_id']]
                : null,
            'comment_reaction' => isset($meta['content_id'])
                ? ['contentId' => $meta['content_id']]
                : null,
            default => null,
        };
    }
}
