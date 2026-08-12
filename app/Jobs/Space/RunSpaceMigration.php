<?php

namespace App\Jobs\Space;

use App\DTOs\Migration\MigrationResult;
use App\Services\Content\Serial\ScopeKeyRemapper;
use App\Jobs\QueuedJob;
use App\Models\Management\Space;
use App\Models\Management\SpaceMigration;
use App\Services\Database\DatabaseConnectionService;
use App\Services\Storage\StorageService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RunSpaceMigration extends QueuedJob
{
    public $tries = 1;

    public $timeout = 1800;

    private array $blockFolderIdMap = [];

    private array $blockIdMap = [];

    private array $blockSlugToIdMap = [];

    private array $assetFolderIdMap = [];

    private array $assetIdMap = [];

    private array $contentIdMap = [];

    private array $dataSourceIdMap = [];

    private MigrationResult $result;

    public function __construct(
        public SpaceMigration $migration
    ) {}

    /**
     * Two migrations writing into the same target space interleave inserts
     * and corrupt the id maps. The lock lives in the default cache (not the
     * per-space DB) and expires with the timeout so a crashed worker cannot
     * deadlock future migrations.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->migration->target_space_id))
                ->expireAfter($this->timeout),
        ];
    }

    protected function execute(): void
    {
        // Fail fast instead of running a partial, interleaved migration (the
        // job has no transactions or resume support).
        $inFlight = SpaceMigration::where('target_space_id', $this->migration->target_space_id)
            ->where('state', 'processing')
            ->whereKeyNot($this->migration->id)
            ->exists();

        if ($inFlight) {
            throw new \RuntimeException(
                'Another migration into this target space is already running. Wait for it to finish and retry.'
            );
        }

        $this->migration->markAsProcessing();
        $this->result = new MigrationResult;

        /** @var DatabaseConnectionService $dbService */
        $dbService = app(DatabaseConnectionService::class);

        $sourceSpace = Space::findOrFail($this->migration->source_space_id);
        $targetSpace = Space::findOrFail($this->migration->target_space_id);

        $sourceConn = $dbService->getDefaultConnection($sourceSpace);
        $targetConn = $dbService->getDefaultConnection($targetSpace);

        $scope = $this->migration->scope;
        $strategy = $this->migration->conflict_strategy;

        if ($scope['blocks'] ?? false) {
            $this->migrateFieldPlugins($sourceConn, $targetConn, $strategy);
            $this->migrateBlockFolders($sourceConn, $targetConn, $strategy);
            $this->migration->updateProgress(10);
            $this->migrateBlockTags($sourceConn, $targetConn, $strategy);
            $this->migrateBlocks($sourceConn, $targetConn, $strategy, $scope['block_templates'] ?? false);
            $this->migration->updateProgress(20);
        }

        if ($scope['assets'] ?? false) {
            $this->migrateAssetFolders($sourceConn, $targetConn, $strategy);
            $this->migration->updateProgress(35);
            $this->migrateAssetTags($sourceConn, $targetConn, $strategy);
            $this->migrateAssets($sourceConn, $targetConn, $strategy, $sourceSpace, $targetSpace);
            $this->migration->updateProgress(50);
        }

        if ($scope['content'] ?? false) {
            $this->migrateContents($sourceConn, $targetConn, $strategy);
            $this->migrateContentSerials($sourceConn, $targetConn);
            $this->migration->updateProgress(80);
        }

        if ($scope['data_sources'] ?? false) {
            $this->migrateDataSources($sourceConn, $targetConn, $strategy);
            $this->migration->updateProgress(90);
        }

        if ($scope['redirects'] ?? false) {
            $this->migrateRedirects($sourceConn, $targetConn, $strategy);
            $this->migration->updateProgress(95);
        }

        $stats = $this->buildStats($targetConn);
        $this->migration->markAsCompleted($stats, $this->result->toArray());
    }

    // -------------------------------------------------------------------------
    // Field Plugins
    // -------------------------------------------------------------------------

    /**
     * Block schemas reference plugins by handle, so plugins migrate ahead of
     * blocks. The handle is unique and immutable, making it the natural match.
     */
    private function migrateFieldPlugins(ConnectionInterface $src, ConnectionInterface $tgt, string $strategy): void
    {
        $this->upsertRows(
            $tgt,
            $strategy,
            'field_plugins',
            $src->table('field_plugins')->get(),
            fn (array $row) => $tgt->table('field_plugins')
                ->where(function ($q) use ($row) {
                    $q->where('external_id', $row['id'])
                        ->orWhere('handle', $row['handle']);
                })
                ->first(),
            fn (array $row) => [
                'external_id' => $row['id'],
                'name' => $row['name'],
                'handle' => $row['handle'],
                'description' => $row['description'],
                'dev_mode' => $row['dev_mode'],
                'dev_url' => $row['dev_url'],
                'code' => $row['code'],
                'code_hash' => $row['code_hash'],
                'code_size' => $row['code_size'],
                'published_at' => $row['published_at'],
                'manifest' => $row['manifest'],
                'is_active' => $row['is_active'],
                'updated_at' => $row['updated_at'],
            ],
        );
    }

    // -------------------------------------------------------------------------
    // Block Folders
    // -------------------------------------------------------------------------

    private function migrateBlockFolders(ConnectionInterface $src, ConnectionInterface $tgt, string $strategy): void
    {
        $this->upsertRows(
            $tgt,
            $strategy,
            'block_folders',
            $this->sortByParentDepth($src->table('block_folders')->get()->all(), 'id', 'parent_id'),
            fn (array $row) => $tgt->table('block_folders')
                ->where('external_id', $row['id'])
                ->first(),
            fn (array $row) => [
                'external_id' => $row['id'],
                'name' => $row['name'],
                'icon' => $row['icon'],
                'color' => $row['color'],
                'parent_id' => $row['parent_id']
                    ? ($this->blockFolderIdMap[$row['parent_id']] ?? null)
                    : null,
                'updated_at' => $row['updated_at'],
            ],
            $this->blockFolderIdMap,
        );
    }

    // -------------------------------------------------------------------------
    // Block Tags (catalog)
    // -------------------------------------------------------------------------

    private function migrateBlockTags(ConnectionInterface $src, ConnectionInterface $tgt, string $strategy): void
    {
        $rows = $src->table('block_tags')->get();

        foreach ($rows as $row) {
            $row = (array) $row;
            $name = $row['name'];

            $existing = $tgt->table('block_tags')->where('name', $name)->first();

            $data = [
                'external_id' => $name,
                'icon' => $row['icon'],
                'color' => $row['color'],
                'updated_at' => $row['updated_at'],
            ];

            if ($existing) {
                if ($strategy === 'skip') {
                    $this->result->incrementSkipped('block_tags');

                    continue;
                }
                if ($strategy === 'merge_newer' && $row['updated_at'] <= $existing->updated_at) {
                    $this->result->incrementSkipped('block_tags');

                    continue;
                }
                $tgt->table('block_tags')->where('name', $name)->update($data);
                $this->result->incrementUpdated('block_tags');
            } else {
                $tgt->table('block_tags')->insert(array_merge($data, [
                    'name' => $name,
                    'created_at' => $row['created_at'],
                ]));
                $this->result->incrementCreated('block_tags');
            }
        }
    }

    // -------------------------------------------------------------------------
    // Blocks
    // -------------------------------------------------------------------------

    private function migrateBlocks(ConnectionInterface $src, ConnectionInterface $tgt, string $strategy, bool $includeTemplates = false): void
    {
        $rows = $src->table('blocks')->whereNull('deleted_at')->get();

        // Build target slug→id map for fallback matching
        $tgt->table('blocks')->whereNull('deleted_at')->get()
            ->each(function ($b) {
                $this->blockSlugToIdMap[$b->slug] = $b->id;
            });

        $this->upsertRows(
            $tgt,
            $strategy,
            'blocks',
            $rows,
            // Match by external_id first, then fall back to slug
            fn (array $row) => $tgt->table('blocks')
                ->where('external_id', $row['id'])
                ->orWhere('slug', $row['slug'])
                ->whereNull('deleted_at')
                ->first(),
            fn (array $row) => [
                'external_id' => $row['id'],
                'slug' => $row['slug'],
                'name' => $row['name'],
                'icon' => $row['icon'],
                'color' => $row['color'],
                'type' => $row['type'],
                'description' => $row['description'],
                'preview_template' => $row['preview_template'],
                'preview_file' => $row['preview_file'],
                'schema' => $row['schema'],
                'editor' => $row['editor'],
                'tags' => $row['tags'],
                'folder_id' => $row['folder_id']
                    ? ($this->blockFolderIdMap[$row['folder_id']] ?? null)
                    : null,
                'updated_at' => $row['updated_at'],
            ],
            $this->blockIdMap,
            function (array $row, string $targetId, bool $created) use ($src, $tgt, $strategy, $includeTemplates) {
                if ($created) {
                    $this->blockSlugToIdMap[$row['slug']] = $targetId;
                }

                // Migrate block templates if in scope
                if ($includeTemplates) {
                    $this->migrateBlockTemplates($src, $tgt, $row['id'], $targetId, $strategy);
                }
            },
        );
    }

    private function migrateBlockTemplates(
        ConnectionInterface $src,
        ConnectionInterface $tgt,
        string $sourceBlockId,
        string $targetBlockId,
        string $strategy
    ): void {
        $this->upsertRows(
            $tgt,
            $strategy,
            'block_templates',
            $src->table('block_templates')
                ->where('block_id', $sourceBlockId)
                ->whereNull('deleted_at')
                ->get(),
            fn (array $tpl) => $tgt->table('block_templates')
                ->where('block_id', $targetBlockId)
                ->where('name', $tpl['name'])
                ->whereNull('deleted_at')
                ->first(),
            fn (array $tpl) => [
                'name' => $tpl['name'],
                'icon' => $tpl['icon'],
                'color' => $tpl['color'],
                'description' => $tpl['description'],
                'content' => $tpl['content'],
                'preview_file' => $tpl['preview_file'],
                'block_id' => $targetBlockId,
                'created_by_id' => null,
                'updated_at' => $tpl['updated_at'],
            ],
        );
    }

    // -------------------------------------------------------------------------
    // Asset Folders
    // -------------------------------------------------------------------------

    private function migrateAssetFolders(ConnectionInterface $src, ConnectionInterface $tgt, string $strategy): void
    {
        $this->upsertRows(
            $tgt,
            $strategy,
            'asset_folders',
            $this->sortByParentDepth($src->table('asset_folders')->whereNull('deleted_at')->get()->all(), 'id', 'parent_id'),
            fn (array $row) => $tgt->table('asset_folders')
                ->where('external_id', $row['id'])
                ->whereNull('deleted_at')
                ->first(),
            fn (array $row) => [
                'external_id' => $row['id'],
                'name' => $row['name'],
                'description' => $row['description'] ?? null,
                'icon' => $row['icon'],
                'color' => $row['color'],
                'parent_id' => $row['parent_id']
                    ? ($this->assetFolderIdMap[$row['parent_id']] ?? null)
                    : null,
                'updated_at' => $row['updated_at'],
            ],
            $this->assetFolderIdMap,
        );
    }

    private function migrateAssetTags(ConnectionInterface $src, ConnectionInterface $tgt, string $strategy): void
    {
        $this->upsertRows(
            $tgt,
            $strategy,
            'asset_tags',
            $src->table('asset_tags')->whereNull('deleted_at')->get(),
            // Match by external_id, and fall back to the name
            fn (array $row) => $tgt->table('asset_tags')
                ->where('external_id', $row['id'])
                ->whereNull('deleted_at')
                ->first()
                ?? $tgt->table('asset_tags')
                    ->where('name', $row['name'])
                    ->whereNull('deleted_at')
                    ->first(),
            fn (array $row) => [
                'external_id' => $row['id'],
                'name' => $row['name'],
                'icon' => $row['icon'],
                'color' => $row['color'],
                'updated_at' => $row['updated_at'],
            ],
        );
    }

    // -------------------------------------------------------------------------
    // Assets
    // -------------------------------------------------------------------------

    private function migrateAssets(
        ConnectionInterface $src,
        ConnectionInterface $tgt,
        string $strategy,
        Space $sourceSpace,
        Space $targetSpace
    ): void {
        /** @var StorageService $storageService */
        $storageService = app(StorageService::class);

        try {
            $srcFilesystem = $storageService->getDefaultStorage($sourceSpace);
        } catch (\Throwable $e) {
            Log::warning('Migration: could not load source storage, assets will be migrated without files', [
                'migration_id' => $this->migration->id,
                'error' => $e->getMessage(),
            ]);
            $srcFilesystem = null;
        }

        try {
            $tgtFilesystem = $storageService->getDefaultStorage($targetSpace);
            $targetStorage = $targetSpace->storages()->where('is_default', true)->first();
            $targetStorageId = $targetStorage?->id;
        } catch (\Throwable $e) {
            Log::warning('Migration: could not load target storage, assets will be migrated without files', [
                'migration_id' => $this->migration->id,
                'error' => $e->getMessage(),
            ]);
            $tgtFilesystem = null;
            $targetStorageId = null;
        }

        $rows = $src->table('assets')->whereNull('deleted_at')->get();

        Log::info('Migration: starting asset migration', [
            'migration_id' => $this->migration->id,
            'source_count' => $rows->count(),
        ]);

        foreach ($rows as $row) {
            $row = (array) $row;
            $externalId = $row['id'];

            $existing = $tgt->table('assets')
                ->where('external_id', $externalId)
                ->whereNull('deleted_at')
                ->first();

            $targetFolderId = $row['folder_id']
                ? ($this->assetFolderIdMap[$row['folder_id']] ?? null)
                : null;

            $targetId = $existing ? $existing->id : (string) Str::ulid();

            // Build target path with target space/asset IDs
            $targetPath = "{$targetSpace->id}/{$targetId}/{$row['filename']}.{$row['extension']}";

            // Update metadata thumbnail paths to point to target space/asset
            $metadata = $this->rewriteAssetMetadataPaths($row['metadata'], $row['id'], $targetId, $sourceSpace->id, $targetSpace->id);

            // Copy physical files
            if ($srcFilesystem && $tgtFilesystem) {
                $this->copyAssetFiles($srcFilesystem, $tgtFilesystem, $row, $targetId, $sourceSpace->id, $targetSpace->id);
            }

            $data = [
                'external_id' => $externalId,
                'filename' => $row['filename'],
                'extension' => $row['extension'],
                'mime_type' => $row['mime_type'],
                'path' => $targetPath,
                'storage_id' => $targetStorageId ?? $row['storage_id'],
                'folder_id' => $targetFolderId,
                'size' => $row['size'],
                'metadata' => $metadata,
                'data' => $row['data'],
                'tags' => $row['tags'],
                'updated_at' => $row['updated_at'],
            ];

            if ($existing) {
                $resolved = $this->resolveConflict($tgt, 'assets', $existing->id, $data, $strategy, $row['updated_at']);
                if ($resolved === 'updated') {
                    $this->result->incrementUpdated('assets');
                } elseif ($resolved === 'skipped') {
                    $this->result->incrementSkipped('assets');
                }
            } else {
                $tgt->table('assets')->insert(array_merge($data, [
                    'id' => $targetId,
                    'created_at' => $row['created_at'],
                ]));
                $this->result->incrementCreated('assets');
            }

            $this->assetIdMap[$externalId] = $targetId;
        }
    }

    private function copyAssetFiles(
        Filesystem $src,
        Filesystem $tgt,
        array $row,
        string $targetId,
        string $sourceSpaceId,
        string $targetSpaceId
    ): void {
        $sourceDir = "{$sourceSpaceId}/{$row['id']}";
        $targetDir = "{$targetSpaceId}/{$targetId}";

        try {
            $files = $src->files($sourceDir);
        } catch (\Throwable $e) {
            Log::warning('Migration: could not list asset directory', [
                'migration_id' => $this->migration->id,
                'asset_id' => $row['id'],
                'path' => $sourceDir,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        foreach ($files as $sourcePath) {
            $filename = basename($sourcePath);
            $targetPath = "{$targetDir}/{$filename}";

            try {
                $stream = $src->readStream($sourcePath);
                if ($stream === false || $stream === null) {
                    throw new \RuntimeException("Could not open stream for {$sourcePath}");
                }
                $tgt->writeStream($targetPath, $stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
            } catch (\Throwable $e) {
                Log::warning('Migration: failed to copy asset file', [
                    'migration_id' => $this->migration->id,
                    'source_path' => $sourcePath,
                    'target_path' => $targetPath,
                    'error' => $e->getMessage(),
                ]);
                $this->result->addError('assets', $row['id'], "File copy failed for {$filename}: {$e->getMessage()}");
            }
        }
    }

    private function rewriteAssetMetadataPaths(
        mixed $metadata,
        string $sourceAssetId,
        string $targetAssetId,
        string $sourceSpaceId,
        string $targetSpaceId
    ): mixed {
        if (! $metadata) {
            return $metadata;
        }

        $decoded = is_string($metadata) ? json_decode($metadata, true) : $metadata;
        if (! is_array($decoded)) {
            return $metadata;
        }

        foreach (['thumbnails', 'generated_thumbnails'] as $key) {
            if (empty($decoded[$key]) || ! is_array($decoded[$key])) {
                continue;
            }

            foreach ($decoded[$key] as &$thumbnail) {
                if (isset($thumbnail['path']) && is_string($thumbnail['path'])) {
                    $thumbnail['path'] = str_replace(
                        "{$sourceSpaceId}/{$sourceAssetId}/",
                        "{$targetSpaceId}/{$targetAssetId}/",
                        $thumbnail['path']
                    );
                }
            }
            unset($thumbnail);
        }

        return json_encode($decoded);
    }

    // -------------------------------------------------------------------------
    // Contents
    // -------------------------------------------------------------------------

    private function migrateContents(ConnectionInterface $src, ConnectionInterface $tgt, string $strategy): void
    {
        $rows = $src->table('contents')->whereNull('deleted_at')->get()->all();
        $sorted = $this->sortByParentDepth($rows, 'id', 'parent_id');

        // Preload source block id → slug once instead of querying per content in
        // the slug-based fallback below.
        $sourceBlockSlugById = $src->table('blocks')->pluck('slug', 'id')->all();

        foreach ($sorted as $row) {
            $row = (array) $row;
            $externalId = $row['id'];

            $existing = $tgt->table('contents')
                ->where('external_id', $externalId)
                ->whereNull('deleted_at')
                ->first();

            // Resolve target block_id via the block map
            $targetBlockId = $this->blockIdMap[$row['block_id']] ?? null;
            if (! $targetBlockId) {
                // Try slug-based fallback
                $sourceBlockSlug = $sourceBlockSlugById[$row['block_id']] ?? null;
                if ($sourceBlockSlug !== null) {
                    $targetBlockId = $this->blockSlugToIdMap[$sourceBlockSlug] ?? null;
                }
            }

            if (! $targetBlockId) {
                $this->result->addError('contents', $externalId, "Block not found in target space for block_id={$row['block_id']}");

                continue;
            }

            $targetParentId = $row['parent_id']
                ? ($this->contentIdMap[$row['parent_id']] ?? null)
                : null;

            $targetI18nParentId = $row['i18n_parent_id']
                ? ($this->contentIdMap[$row['i18n_parent_id']] ?? null)
                : null;

            if ($existing) {
                $this->contentIdMap[$externalId] = $existing->id;
                if ($strategy === 'skip') {
                    $this->result->incrementSkipped('contents');

                    continue;
                }
                if ($strategy === 'merge_newer' && $row['updated_at'] <= $existing->updated_at) {
                    $this->result->incrementSkipped('contents');

                    continue;
                }

                // Migrate/update versions and get new version IDs
                [$currentVersionId, $publishedVersionId] = $this->migrateContentVersions(
                    $src, $tgt, $row, $existing->id, $strategy
                );

                $tgt->table('contents')->where('id', $existing->id)->update([
                    'external_id' => $externalId,
                    'block_id' => $targetBlockId,
                    'parent_id' => $targetParentId,
                    'position' => $row['position'] ?? 0,
                    'name' => $row['name'],
                    'slug' => $row['slug'],
                    'full_slug' => $row['full_slug'],
                    'language_iso' => $row['language_iso'],
                    'i18n_parent_id' => $targetI18nParentId,
                    'content' => $row['content'],
                    'settings' => $row['settings'],
                    'current_version_id' => $currentVersionId,
                    'published_version_id' => $publishedVersionId,
                    'searchable_content' => $row['searchable_content'],
                    'published_at' => $row['published_at'],
                    'first_published_at' => $row['first_published_at'],
                    'updated_at' => $row['updated_at'],
                ]);
                $this->result->incrementUpdated('contents');
            } else {
                $targetId = (string) Str::ulid();
                $this->contentIdMap[$externalId] = $targetId;

                // Migrate versions first to get their new IDs
                [$currentVersionId, $publishedVersionId] = $this->migrateContentVersions(
                    $src, $tgt, $row, $targetId, $strategy
                );

                $tgt->table('contents')->insert([
                    'id' => $targetId,
                    'external_id' => $externalId,
                    'block_id' => $targetBlockId,
                    'parent_id' => $targetParentId,
                    'position' => $row['position'] ?? 0,
                    'name' => $row['name'],
                    'slug' => $row['slug'],
                    'full_slug' => $row['full_slug'],
                    'language_iso' => $row['language_iso'],
                    'i18n_parent_id' => $targetI18nParentId,
                    'content' => $row['content'],
                    'settings' => $row['settings'],
                    'current_version_id' => $currentVersionId,
                    'published_version_id' => $publishedVersionId,
                    'searchable_content' => $row['searchable_content'],
                    'published_at' => $row['published_at'],
                    'first_published_at' => $row['first_published_at'],
                    'created_at' => $row['created_at'],
                    'updated_at' => $row['updated_at'],
                ]);
                $this->result->incrementCreated('contents');
            }
        }
    }

    /**
     * Carry the serial allocation ledger across with the content.
     *
     * Without this the target space has entries whose payloads contain serial
     * values but no reservations behind them, so the next entry created there
     * starts counting from one and hands out identifiers that already exist.
     * Scope keys embed block and parent ids, so they are rebuilt from the id
     * maps rather than copied.
     */
    private function migrateContentSerials(ConnectionInterface $src, ConnectionInterface $tgt): void
    {
        if (! $src->getSchemaBuilder()->hasTable('content_serials')
            || ! $tgt->getSchemaBuilder()->hasTable('content_serials')
        ) {
            return;
        }

        $remapper = app(ScopeKeyRemapper::class);

        foreach ($src->table('content_serials')->orderBy('scope_key')->orderBy('number')->cursor() as $row) {
            $row = (array) $row;
            $targetContentId = $this->contentIdMap[$row['content_id']] ?? null;

            if (! $targetContentId) {
                continue;
            }

            $scopeKey = $remapper->remap($row['scope_key'], $this->blockIdMap, $this->contentIdMap);
            $uniqueKey = $row['unique_key'] !== null
                ? $remapper->remap($row['unique_key'], $this->blockIdMap, $this->contentIdMap)
                : null;

            // Ids that did not migrate would collapse different scopes onto the
            // same key and hand out duplicate numbers.
            if ($scopeKey === null || ($row['unique_key'] !== null && $uniqueKey === null)) {
                $this->result->addError(
                    'content_serials',
                    (string) $row['id'],
                    "Scope references content or blocks that were not migrated: {$row['scope_key']}",
                );

                continue;
            }

            $existing = $tgt->table('content_serials')
                ->where('content_id', $targetContentId)
                ->where('field_key', $row['field_key'])
                ->first();

            $data = [
                'content_id' => $targetContentId,
                'field_key' => $row['field_key'],
                'scope_key' => $scopeKey,
                'unique_key' => $uniqueKey,
                'number' => $row['number'],
                'value' => $row['value'],
                'updated_at' => $row['updated_at'],
            ];

            // The target may already reserve this number or value for content
            // of its own (e.g. entries created there before the migration).
            // That is a data conflict to report, not a reason to abort the
            // whole migration mid-way with a constraint violation.
            try {
                if ($existing) {
                    $tgt->table('content_serials')->where('id', $existing->id)->update($data);
                    $this->result->incrementUpdated('content_serials');

                    continue;
                }

                $tgt->table('content_serials')->insert(array_merge($data, [
                    'id' => strtolower((string) Str::ulid()),
                    'created_at' => $row['created_at'],
                ]));
                $this->result->incrementCreated('content_serials');
            } catch (QueryException) {
                $this->result->addError(
                    'content_serials',
                    (string) $row['id'],
                    "Reservation conflicts with one already in the target space: {$row['value']} ({$scopeKey})",
                );
            }
        }
    }

    /**
     * Migrate current + published versions for a content record.
     * Returns [targetCurrentVersionId, targetPublishedVersionId|null].
     */
    private function migrateContentVersions(
        ConnectionInterface $src,
        ConnectionInterface $tgt,
        array $contentRow,
        string $targetContentId,
        string $strategy
    ): array {
        $versionIdMap = [];

        $versionIds = array_filter(array_unique([
            $contentRow['current_version_id'],
            $contentRow['published_version_id'],
        ]));

        foreach ($versionIds as $sourceVersionId) {
            $version = $src->table('content_versions')->where('id', $sourceVersionId)->first();
            if (! $version) {
                continue;
            }
            $version = (array) $version;

            $existing = $tgt->table('content_versions')
                ->where('external_id', $sourceVersionId)
                ->where('content_id', $targetContentId)
                ->first();

            $data = [
                'external_id' => $sourceVersionId,
                'message' => $version['message'],
                'content' => $version['content'],
                'asset_ids' => $version['asset_ids'],
                'relation_ids' => $version['relation_ids'],
                'link_ids' => $version['link_ids'],
                'content_id' => $targetContentId,
                'parent_id' => null,
                'release_id' => null,
                'created_by_id' => null,
                'published_by_id' => null,
                'published_at' => $version['published_at'],
                'scheduled_at' => null,
                'created_at' => $version['created_at'],
            ];

            if ($existing) {
                if ($strategy !== 'skip') {
                    $tgt->table('content_versions')->where('id', $existing->id)->update($data);
                    $this->result->incrementUpdated('content_versions');
                } else {
                    $this->result->incrementSkipped('content_versions');
                }
                $versionIdMap[$sourceVersionId] = $existing->id;
            } else {
                $newVersionId = (string) Str::ulid();
                $tgt->table('content_versions')->insert(array_merge($data, ['id' => $newVersionId]));
                $this->result->incrementCreated('content_versions');
                $versionIdMap[$sourceVersionId] = $newVersionId;
            }
        }

        $targetCurrentVersionId = $versionIdMap[$contentRow['current_version_id']] ?? null;
        $targetPublishedVersionId = $contentRow['published_version_id']
            ? ($versionIdMap[$contentRow['published_version_id']] ?? null)
            : null;

        return [$targetCurrentVersionId, $targetPublishedVersionId];
    }

    // -------------------------------------------------------------------------
    // Data Sources
    // -------------------------------------------------------------------------

    private function migrateDataSources(ConnectionInterface $src, ConnectionInterface $tgt, string $strategy): void
    {
        $this->upsertRows(
            $tgt,
            $strategy,
            'data_sources',
            $src->table('data_sources')->get(),
            fn (array $ds) => $tgt->table('data_sources')
                ->where(function ($q) use ($ds) {
                    $q->where('external_id', $ds['id'])
                        ->orWhere('slug', $ds['slug']);
                })
                ->first(),
            fn (array $ds) => [
                'external_id' => $ds['id'],
                'name' => $ds['name'],
                'slug' => $ds['slug'],
                'description' => $ds['description'],
                'dimensions' => $ds['dimensions'],
                'settings' => $ds['settings'],
                'is_active' => $ds['is_active'],
                'updated_at' => $ds['updated_at'],
            ],
            $this->dataSourceIdMap,
            // Migrate entries for this data source
            fn (array $ds, string $targetId) => $this->migrateDataEntries($src, $tgt, $ds['id'], $targetId, $strategy),
        );
    }

    private function migrateDataEntries(
        ConnectionInterface $src,
        ConnectionInterface $tgt,
        string $sourceDataSourceId,
        string $targetDataSourceId,
        string $strategy
    ): void {
        $this->upsertRows(
            $tgt,
            $strategy,
            'data_entries',
            $src->table('data_entries')->where('data_source_id', $sourceDataSourceId)->get(),
            fn (array $entry) => $tgt->table('data_entries')
                ->where('data_source_id', $targetDataSourceId)
                ->where('key', $entry['key'])
                ->first(),
            fn (array $entry) => [
                'external_id' => $entry['id'],
                'data_source_id' => $targetDataSourceId,
                'key' => $entry['key'],
                'value' => $entry['value'],
                'dimensions' => $entry['dimensions'],
                'is_active' => $entry['is_active'],
                'updated_at' => $entry['updated_at'],
            ],
        );
    }

    // -------------------------------------------------------------------------
    // Redirects
    // -------------------------------------------------------------------------

    private function migrateRedirects(ConnectionInterface $src, ConnectionInterface $tgt, string $strategy): void
    {
        $this->upsertRows(
            $tgt,
            $strategy,
            'redirects',
            $src->table('redirects')->get(),
            fn (array $row) => $tgt->table('redirects')
                ->where(function ($q) use ($row) {
                    $q->where('external_id', $row['id'])
                        ->orWhere('source', $row['source']);
                })
                ->first(),
            fn (array $row) => [
                'external_id' => $row['id'],
                'source' => $row['source'],
                'target' => $row['target'],
                'status_code' => $row['status_code'],
                'hits' => 0,
                'last_used_at' => null,
                'updated_at' => $row['updated_at'],
            ],
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Sort a flat list of records so parents always appear before children.
     */
    private function sortByParentDepth(array $rows, string $idKey, string $parentKey): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $indexed[$row[$idKey]] = $row;
        }

        $sorted = [];
        $visited = [];

        $visit = function (string $id) use (&$visit, &$indexed, &$sorted, &$visited, $parentKey): void {
            if (isset($visited[$id])) {
                return;
            }
            $visited[$id] = true;
            $node = $indexed[$id];
            if ($node[$parentKey] && isset($indexed[$node[$parentKey]])) {
                $visit($node[$parentKey]);
            }
            $sorted[] = $node;
        };

        foreach (array_keys($indexed) as $id) {
            $visit($id);
        }

        return $sorted;
    }

    /**
     * Copy a set of source rows into a target table.
     *
     * Every straightforward table migrates the same way: find the counterpart
     * row, either resolve the conflict or insert it under a fresh ULID, and
     * count the outcome. Only the matching rule and the column list differ, so
     * both arrive as closures.
     *
     * @param  iterable<mixed>  $rows  source rows (pre-sorted where order matters, e.g. folder trees)
     * @param  \Closure(array): ?object  $match  locates the counterpart row in the target table
     * @param  \Closure(array): array  $buildData  column values to write (without id/created_at)
     * @param  array<string, string>|null  $idMap  source id → target id, written by reference for later remapping
     * @param  \Closure(array, string, bool): void|null  $afterEach  runs per row with (row, targetId, wasCreated)
     */
    private function upsertRows(
        ConnectionInterface $tgt,
        string $strategy,
        string $table,
        iterable $rows,
        \Closure $match,
        \Closure $buildData,
        ?array &$idMap = null,
        ?\Closure $afterEach = null,
    ): void {
        foreach ($rows as $row) {
            $row = (array) $row;

            $existing = $match($row);
            $data = $buildData($row);

            if ($existing) {
                $targetId = $existing->id;
                $resolved = $this->resolveConflict($tgt, $table, $existing->id, $data, $strategy, $row['updated_at']);
                if ($resolved === 'updated') {
                    $this->result->incrementUpdated($table);
                } elseif ($resolved === 'skipped') {
                    $this->result->incrementSkipped($table);
                }
            } else {
                $targetId = (string) Str::ulid();
                $tgt->table($table)->insert(array_merge($data, [
                    'id' => $targetId,
                    'created_at' => $row['created_at'],
                ]));
                $this->result->incrementCreated($table);
            }

            if ($idMap !== null) {
                $idMap[$row['id']] = $targetId;
            }

            if ($afterEach) {
                $afterEach($row, $targetId, $existing === null);
            }
        }
    }

    /**
     * Apply conflict strategy for an existing record.
     * Returns 'updated', 'skipped', or 'noop'.
     */
    private function resolveConflict(
        ConnectionInterface $tgt,
        string $table,
        string $targetId,
        array $data,
        string $strategy,
        ?string $sourceUpdatedAt
    ): string {
        if ($strategy === 'skip') {
            return 'skipped';
        }

        if ($strategy === 'merge_newer') {
            $existing = $tgt->table($table)->where('id', $targetId)->first();
            if ($existing && $sourceUpdatedAt <= $existing->updated_at) {
                return 'skipped';
            }
        }

        $tgt->table($table)->where('id', $targetId)->update($data);

        return 'updated';
    }

    private function buildStats(ConnectionInterface $tgt): array
    {
        return [
            'field_plugins' => $tgt->table('field_plugins')->count(),
            'block_folders' => $tgt->table('block_folders')->count(),
            'block_tags' => $tgt->table('block_tags')->count(),
            'blocks' => $tgt->table('blocks')->whereNull('deleted_at')->count(),
            'asset_folders' => $tgt->table('asset_folders')->whereNull('deleted_at')->count(),
            'assets' => $tgt->table('assets')->whereNull('deleted_at')->count(),
            'contents' => $tgt->table('contents')->whereNull('deleted_at')->count(),
            'content_versions' => $tgt->table('content_versions')->count(),
            'data_sources' => $tgt->table('data_sources')->count(),
            'data_entries' => $tgt->table('data_entries')->count(),
            'redirects' => $tgt->table('redirects')->count(),
        ];
    }

    protected function handleFailure(\Throwable $e): void
    {
        Log::error('Space migration failed', [
            'migration_id' => $this->migration->id,
            'source_space_id' => $this->migration->source_space_id,
            'target_space_id' => $this->migration->target_space_id,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        $this->migration->markAsFailed($e->getMessage());
    }

    public function tags(): array
    {
        return [
            'migration:'.$this->migration->id,
            'source-space:'.$this->migration->source_space_id,
            'target-space:'.$this->migration->target_space_id,
        ];
    }
}
