<?php

namespace App\Actions\Content;

use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Models\User;
use App\Services\Content\ContentHierarchyValidator;
use App\Services\Content\ContentI18nValidator;
use App\Services\Content\ContentPositionService;
use App\Services\Content\Schema\ContentSchemaValidationResult;
use App\Services\Content\Schema\ContentSchemaValidator;
use App\Services\Content\Schema\SchemaDefaultsResolver;
use App\Services\Content\Serial\ContentSerialAssigner;
use App\Services\Content\Serial\ContentSlugComposer;
use App\Services\Content\Serial\SerialCollisionException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateContent
{
    public function __construct(
        private readonly ContentHierarchyValidator $contentHierarchyValidator,
        private readonly ContentI18nValidator $validator,
        private readonly ContentSchemaValidator $contentSchemaValidator,
        private readonly ContentPositionService $contentPositionService,
        private readonly SchemaDefaultsResolver $schemaDefaultsResolver,
        private readonly ContentSerialAssigner $serialAssigner,
        private readonly ContentSlugComposer $slugComposer,
    ) {}

    protected function throwIfValidationFails(
        ContentSchemaValidationResult $contentValidation,
        bool $force = false,
    ): void {
        if (! $contentValidation->isValid()) {
            throw ValidationException::withMessages($contentValidation->errors);
        }

        if (! $force && $contentValidation->hasWarnings()) {
            throw ValidationException::withMessages($contentValidation->warnings);
        }
    }

    public function execute(
        array $data,
        Content $content,
        Space $space,
        Authenticatable|User|null $owner,
        ?Block $block = null,
        bool $touchSpace = true,
    ) {
        if (! (bool) data_get($data, 'force', false)) {
            $errors = $this->validator->validate($space, $data);
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
        }

        \DB::transaction(function () use ($data, $content, $owner, $space, $block, $touchSpace) {
            if (! \Arr::has($data, 'language_iso')) {
                $data['language_iso'] = $space->settings->getDefaultLanguage();
            }
            $sortingEnabled = $space->settings->isContentSortingEnabled();
            $requestedPosition = $sortingEnabled && array_key_exists('position', $data) && $data['position'] !== null
                ? (int) $data['position']
                : null;
            if ($sortingEnabled) {
                $data['position'] = $requestedPosition
                    ?? $this->contentPositionService->nextPosition($data['parent_id'] ?? null, $data['language_iso']);
            } else {
                // Sorting disabled: leave position at the column default (0) so ordering falls back to name.
                unset($data['position']);
            }

            /** @var Block $block */
            $block ??= Block::query()->findOrFail($data['block_id']);
            $parent = isset($data['parent_id']) ? Content::query()->with('block')->find($data['parent_id']) : null;

            $this->contentHierarchyValidator->validatePlacement(
                $space,
                $block,
                $parent,
                null,
                $data['language_iso'] ?? null,
            );

            // Allow empty content submissions: if no content (null) or an empty array is provided,
            // skip schema validation and seed the block's field defaults instead. Defaults are
            // validated when the block schema is saved, not per content creation, because a
            // partial set of defaults must not trip required-field validation here.
            $submittedContent = data_get($data, 'content', null);

            if ($submittedContent === null || (is_array($submittedContent) && empty($submittedContent))) {
                $validatedContent = $this->schemaDefaultsResolver->resolve($block->schema);
            } else {
                $contentValidation = $this->contentSchemaValidator->validateSubmission(
                    $space,
                    $block,
                    $submittedContent,
                    null,
                    $data['language_iso'] ?? null,
                    $data['i18n_parent_id'] ?? null,
                    'save',
                );

                $this->throwIfValidationFails($contentValidation, (bool) data_get($data, 'force', false));

                $validatedContent = $contentValidation->content;
            }

            unset($data['content']);
            unset($data['force']);
            $content->fill($data);

            // The id is needed before the version is written: the ledger row
            // points at it, and the allocated value has to be part of the very
            // first version rather than of a follow-up save.
            $content->id = strtolower((string) Str::ulid());

            try {
                $validatedContent = $this->serialAssigner->assignOnCreate(
                    $space,
                    $block,
                    $content,
                    $parent,
                    $validatedContent,
                );
            } catch (SerialCollisionException $exception) {
                throw ValidationException::withMessages([
                    'content.'.$exception->fieldKey => [$exception->getMessage()],
                ]);
            }

            $this->applySlugPattern($block, $content, $parent, $validatedContent, $data);

            $version = ContentVersion::createWithContentContext([
                'content' => $validatedContent,
                'content_id' => $content->id,
                'created_by_id' => $owner->id,
            ], $content->setRelation('block', $block));
            $content->current_version_id = $version->id;
            $content->save();

            if ($sortingEnabled && $requestedPosition !== null) {
                $this->contentPositionService->placeNewContent($content, $requestedPosition);
            }

            if ($touchSpace) {
                $space->touch('content_updated_at');
            }
        });
    }

    /**
     * Seed the slug from the block's slug pattern.
     *
     * Only when the client sent none: an explicit slug is always the editor's
     * decision. Blocks without a pattern keep the historic behaviour, where the
     * slug is whatever the client derived from the name.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $data
     */
    protected function applySlugPattern(
        Block $block,
        Content $content,
        ?Content $parent,
        array $values,
        array $data,
    ): void {
        $submittedSlug = $data['slug'] ?? null;

        if (is_string($submittedSlug) && trim($submittedSlug) !== '') {
            return;
        }

        $content->slug = $this->slugComposer->uniqueAmongSiblings(
            $this->slugComposer->compose(
                $block,
                $parent,
                (string) $content->language_iso,
                $values,
                (string) $content->name,
            ),
            $content->parent_id,
            (string) $content->language_iso,
        );
    }
}
