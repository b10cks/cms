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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RunSpaceMigration extends QueuedJob
{
    public $tries = 1;

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

    protected function execute(): void
    {
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
        $rows = $src->table('field_plugins')->get();

        foreach ($rows as $row) {
            $row = (array) $row;
            $externalId = $row['id'];

            $existing = $tgt->table('field_plugins')
                ->where(function ($q) use ($externalId, $row) {
                    $q->where('external_id', $externalId)
                        ->orWhere('handle', $row['handle']);
                })
                ->first();

            $data = [
                'external_id' => $externalId,
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
            ];

            if ($existing) {
                $resolved = $this->resolveConflict($tgt, 'field_plugins', $existing->id, $data, $strategy, $row['updated_at']);
                if ($resolved === 'updated') {
                    $this->result->incrementUpdated('field_plugins');
                } elseif ($resolved === 'skipped') {
                    $this->result->incrementSkipped('field_plugins');
                }
            } else {
                $tgt->table('field_plugins')->insert(array_merge($data, [
                    'id' => (string) Str::ulid(),
                    'created_at' => $row['created_at'],
                ]));
                $this->result->incrementCreated('field_plugins');
            }
        }
    }

    // -------------------------------------------------------------------------
    // Block Folders
    // -------------------------------------------------------------------------

    private function migrateBlockFolders(ConnectionInterface $src, ConnectionInterface $tgt, string $strategy): void
    {
        $rows = $src->table('block_folders')->get()->all();
        $sorted = $this->sortByParentDepth($rows, 'id', 'parent_id');

        foreach ($sorted as $row) {
            $row = (array) $row;
            $externalId = $row['id'];

            $existing = $tgt->table('block_folders')
                ->where('external_id', $externalId)
                ->first();

            $targetParentId = $row['parent_id']
                ? ($this->blockFolderIdMap[$row['parent_id']] ?? null)
                : null;

            $data = [
                'external_id' => $externalId,
                'name' => $row['name'],
                'icon' => $row['icon'],
                'color' => $row['color'],
                'parent_id' => $targetParentId,
                'updated_at' => $row['updated_at'],
            ];

            if ($existing) {
                $targetId = $existing->id;
                $resolved = $this->resolveConflict($tgt, 'block_folders', $existing->id, $data, $strategy, $row['updated_at']);
                if ($resolved === 'updated') {
                    $this->result->incrementUpdated('block_folders');
                } elseif ($resolved === 'skipped') {
                    $this->result->incrementSkipped('block_folders');
                }
            } else {
                $targetId = (string) Str::ulid();
                $tgt->table('block_folders')->insert(array_merge($data, [
                    'id' => $targetId,
                    'created_at' => $row['created_at'],
                ]));
                $this->result->incrementCreated('block_folders');
            }

            $this->blockFolderIdMap[$externalId] = $targetId;
        }
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

        foreach ($rows as $row) {
            $row = (array) $row;
            $externalId = $row['id'];
            $slug = $row['slug'];

            // Match by external_id first, then fall back to slug
            $existing = $tgt->table('blocks')
                ->where('external_id', $externalId)
                ->orWhere('slug', $slug)
                ->whereNull('deleted_at')
                ->first();

            $targetFolderId = $row['folder_id']
                ? ($this->blockFolderIdMap[$row['folder_id']] ?? null)
                : null;

            $data = [
                'external_id' => $externalId,
                'slug' => $slug,
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
                'folder_id' => $targetFolderId,
                'updated_at' => $row['updated_at'],
            ];

            if ($existing) {
                $targetId = $existing->id;
                $resolved = $this->resolveConflict($tgt, 'blocks', $existing->id, $data, $strategy, $row['updated_at']);
                if ($resolved === 'updated') {
                    $this->result->incrementUpdated('blocks');
                } elseif ($resolved === 'skipped') {
                    $this->result->incrementSkipped('blocks');
                }
            } else {
                $targetId = (string) Str::ulid();
                $tgt->table('blocks')->insert(array_merge($data, [
                    'id' => $targetId,
                    'created_at' => $row['created_at'],
                ]));
                $this->result->incrementCreated('blocks');
                $this->blockSlugToIdMap[$slug] = $targetId;
            }

            $this->blockIdMap[$externalId] = $targetId;

            // Migrate block templates if in scope
            if ($includeTemplates) {
                $this->migrateBlockTemplates($src, $tgt, $externalId, $targetId, $strategy);
            }
        }
    }

    private function migrateBlockTemplates(
        ConnectionInterface $src,
        ConnectionInterface $tgt,
        string $sourceBlockId,
        string $targetBlockId,
        string $strategy
    ): void {
        $templates = $src->table('block_templates')
            ->where('block_id', $sourceBlockId)
            ->whereNull('deleted_at')
            ->get();

        foreach ($templates as $tpl) {
            $tpl = (array) $tpl;
            $existing = $tgt->table('block_templates')
                ->where('block_id', $targetBlockId)
                ->where('name', $tpl['name'])
                ->whereNull('deleted_at')
                ->first();

            $data = [
                'name' => $tpl['name'],
                'icon' => $tpl['icon'],
                'color' => $tpl['color'],
                'description' => $tpl['description'],
                'content' => $tpl['content'],
                'preview_file' => $tpl['preview_file'],
                'block_id' => $targetBlockId,
                'created_by_id' => null,
                'updated_at' => $tpl['updated_at'],
            ];

            if ($existing) {
                if ($strategy === 'skip') {
                    $this->result->incrementSkipped('block_templates');

                    continue;
                }
                if ($strategy === 'merge_newer' && $tpl['updated_at'] <= $existing->updated_at) {
                    $this->result->incrementSkipped('block_templates');

                    continue;
                }
                $tgt->table('block_templates')->where('id', $existing->id)->update($data);
                $this->result->incrementUpdated('block_templates');
            } else {
                $tgt->table('block_templates')->insert(array_merge($data, [
                    'id' => (string) Str::ulid(),
                    'created_at' => $tpl['created_at'],
                ]));
                $this->result->incrementCreated('block_templates');
            }
        }
    }

    // -------------------------------------------------------------------------
    // Asset Folders
    // -------------------------------------------------------------------------

    private function migrateAssetFolders(ConnectionInterface $src, ConnectionInterface $tgt, string $strategy): void
    {
        $rows = $src->table('asset_folders')->whereNull('deleted_at')->get()->all();
        $sorted = $this->sortByParentDepth($rows, 'id', 'parent_id');

        foreach ($sorted as $row) {
            $row = (array) $row;
            $externalId = $row['id'];

            $existing = $tgt->table('asset_folders')
                ->where('external_id', $externalId)
                ->whereNull('deleted_at')
                ->first();

            $targetParentId = $row['parent_id']
                ? ($this->assetFolderIdMap[$row['parent_id']] ?? null)
                : null;

            $data = [
                'external_id' => $externalId,
                'name' => $row['name'],
                'description' => $row['description'] ?? null,
                'icon' => $row['icon'],
                'color' => $row['color'],
                'parent_id' => $targetParentId,
                'updated_at' => $row['updated_at'],
            ];

            if ($existing) {
                $targetId = $existing->id;
                $resolved = $this->resolveConflict($tgt, 'asset_folders', $existing->id, $data, $strategy, $row['updated_at']);
                if ($resolved === 'updated') {
                    $this->result->incrementUpdated('asset_folders');
                } elseif ($resolved === 'skipped') {
                    $this->result->incrementSkipped('asset_folders');
                }
            } else {
                $targetId = (string) Str::ulid();
                $tgt->table('asset_folders')->insert(array_merge($data, [
                    'id' => $targetId,
                    'created_at' => $row['created_at'],
                ]));
                $this->result->incrementCreated('asset_folders');
            }

            $this->assetFolderIdMap[$externalId] = $targetId;
        }
    }

    private function migrateAssetTags(ConnectionInterface $src, ConnectionInterface $tgt, string $strategy): void
    {
        $rows = $src->table('asset_tags')->whereNull('deleted_at')->get();

        foreach ($rows as $row) {
            $row = (array) $row;
            $externalId = $row['id'];

            $existing = $tgt->table('asset_tags')
                ->where('external_id', $externalId)
                ->whereNull('deleted_at')
                ->first();

            // Also try matching by name as fallback
            if (! $existing) {
                $existing = $tgt->table('asset_tags')
                    ->where('name', $row['name'])
                    ->whereNull('deleted_at')
                    ->first();
            }

            $data = [
                'external_id' => $externalId,
                'name' => $row['name'],
                'icon' => $row['icon'],
                'color' => $row['color'],
                'updated_at' => $row['updated_at'],
            ];

            if ($existing) {
                $resolved = $this->resolveConflict($tgt, 'asset_tags', $existing->id, $data, $strategy, $row['updated_at']);
                if ($resolved === 'updated') {
                    $this->result->incrementUpdated('asset_tags');
                } elseif ($resolved === 'skipped') {
                    $this->result->incrementSkipped('asset_tags');
                }
            } else {
                $tgt->table('asset_tags')->insert(array_merge($data, [
                    'id' => (string) Str::ulid(),
                    'created_at' => $row['created_at'],
                ]));
                $this->result->incrementCreated('asset_tags');
            }
        }
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

        if (! empty($decoded['thumbnails']) && is_array($decoded['thumbnails'])) {
            foreach ($decoded['thumbnails'] as &$thumbnail) {
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
        $dataSources = $src->table('data_sources')->get();

        foreach ($dataSources as $ds) {
            $ds = (array) $ds;
            $externalId = $ds['id'];

            $existing = $tgt->table('data_sources')
                ->where(function ($q) use ($externalId, $ds) {
                    $q->where('external_id', $externalId)
                        ->orWhere('slug', $ds['slug']);
                })
                ->first();

            $data = [
                'external_id' => $externalId,
                'name' => $ds['name'],
                'slug' => $ds['slug'],
                'description' => $ds['description'],
                'dimensions' => $ds['dimensions'],
                'settings' => $ds['settings'],
                'is_active' => $ds['is_active'],
                'updated_at' => $ds['updated_at'],
            ];

            if ($existing) {
                $targetId = $existing->id;
                $resolved = $this->resolveConflict($tgt, 'data_sources', $existing->id, $data, $strategy, $ds['updated_at']);
                if ($resolved === 'updated') {
                    $this->result->incrementUpdated('data_sources');
                } elseif ($resolved === 'skipped') {
                    $this->result->incrementSkipped('data_sources');
                }
            } else {
                $targetId = (string) Str::ulid();
                $tgt->table('data_sources')->insert(array_merge($data, [
                    'id' => $targetId,
                    'created_at' => $ds['created_at'],
                ]));
                $this->result->incrementCreated('data_sources');
            }

            $this->dataSourceIdMap[$externalId] = $targetId;

            // Migrate entries for this data source
            $this->migrateDataEntries($src, $tgt, $externalId, $targetId, $strategy);
        }
    }

    private function migrateDataEntries(
        ConnectionInterface $src,
        ConnectionInterface $tgt,
        string $sourceDataSourceId,
        string $targetDataSourceId,
        string $strategy
    ): void {
        $entries = $src->table('data_entries')
            ->where('data_source_id', $sourceDataSourceId)
            ->get();

        foreach ($entries as $entry) {
            $entry = (array) $entry;
            $externalId = $entry['id'];

            $existing = $tgt->table('data_entries')
                ->where('data_source_id', $targetDataSourceId)
                ->where('key', $entry['key'])
                ->first();

            $data = [
                'external_id' => $externalId,
                'data_source_id' => $targetDataSourceId,
                'key' => $entry['key'],
                'value' => $entry['value'],
                'dimensions' => $entry['dimensions'],
                'is_active' => $entry['is_active'],
                'updated_at' => $entry['updated_at'],
            ];

            if ($existing) {
                if ($strategy === 'skip') {
                    $this->result->incrementSkipped('data_entries');

                    continue;
                }
                if ($strategy === 'merge_newer' && $entry['updated_at'] <= $existing->updated_at) {
                    $this->result->incrementSkipped('data_entries');

                    continue;
                }
                $tgt->table('data_entries')->where('id', $existing->id)->update($data);
                $this->result->incrementUpdated('data_entries');
            } else {
                $tgt->table('data_entries')->insert(array_merge($data, [
                    'id' => (string) Str::ulid(),
                    'created_at' => $entry['created_at'],
                ]));
                $this->result->incrementCreated('data_entries');
            }
        }
    }

    // -------------------------------------------------------------------------
    // Redirects
    // -------------------------------------------------------------------------

    private function migrateRedirects(ConnectionInterface $src, ConnectionInterface $tgt, string $strategy): void
    {
        $rows = $src->table('redirects')->get();

        foreach ($rows as $row) {
            $row = (array) $row;
            $externalId = $row['id'];

            $existing = $tgt->table('redirects')
                ->where(function ($q) use ($externalId, $row) {
                    $q->where('external_id', $externalId)
                        ->orWhere('source', $row['source']);
                })
                ->first();

            $data = [
                'external_id' => $externalId,
                'source' => $row['source'],
                'target' => $row['target'],
                'status_code' => $row['status_code'],
                'hits' => 0,
                'last_used_at' => null,
                'updated_at' => $row['updated_at'],
            ];

            if ($existing) {
                if ($strategy === 'skip') {
                    $this->result->incrementSkipped('redirects');

                    continue;
                }
                if ($strategy === 'merge_newer' && $row['updated_at'] <= $existing->updated_at) {
                    $this->result->incrementSkipped('redirects');

                    continue;
                }
                $tgt->table('redirects')->where('id', $existing->id)->update($data);
                $this->result->incrementUpdated('redirects');
            } else {
                $tgt->table('redirects')->insert(array_merge($data, [
                    'id' => (string) Str::ulid(),
                    'created_at' => $row['created_at'],
                ]));
                $this->result->incrementCreated('redirects');
            }
        }
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
