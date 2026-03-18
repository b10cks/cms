<?php

namespace App\Services\Content;

use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Services\Content\Schema\ContentSchemaBuilder;
use App\Services\Content\Schema\ContentSchemaTree;
use App\Services\Content\Schema\ContentSchemaValidator;
use App\Services\Content\Schema\FieldValueResolver;
use App\Services\Content\Schema\FieldVisibilityPruner;
use Illuminate\Validation\ValidationException;

class ContentSchemaService
{
    public function __construct(
        protected ContentI18nResolver $contentI18nResolver,
        protected ContentSchemaBuilder $contentSchemaBuilder,
        protected ContentSchemaValidator $contentSchemaValidator,
        protected FieldVisibilityPruner $fieldVisibilityPruner,
        protected FieldValueResolver $fieldValueResolver,
    ) {}

    /**
     * @throws ValidationException
     */
    public function validateAndSanitize(
        Space $space,
        Block $block,
        array $contentData,
        ?Content $content = null,
        ?string $languageIso = null,
    ): array {
        $tree = $this->buildTreeForMutation($space, $block, $contentData, $content, $languageIso);
        $this->contentSchemaValidator->prepare($tree);

        $errors = $this->contentSchemaValidator->validate($tree);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $this->fieldVisibilityPruner->prune($tree);
    }

    public function buildTreeForMutation(
        Space $space,
        Block $block,
        array $contentData,
        ?Content $content = null,
        ?string $languageIso = null,
    ): ContentSchemaTree {
        $effectiveBase = $this->resolveEffectiveBaseContent($space, $content, $contentData, $languageIso);
        $effectiveContent = $this->fieldValueResolver->overlay($effectiveBase, $contentData);

        return $this->contentSchemaBuilder->build($block, $contentData, $effectiveContent);
    }

    public function buildTreeForIndexing(Space $space, Content $content): ContentSchemaTree
    {
        $content->loadMissing('block');

        $effectiveContent = $this->contentI18nResolver
            ->resolve($space, $content, $content->language_iso, 'published')
            ->effectiveContent;

        return $this->contentSchemaBuilder->build($content->block, $effectiveContent, $effectiveContent);
    }

    protected function resolveEffectiveBaseContent(
        Space $space,
        ?Content $content,
        array $contentData,
        ?string $languageIso,
    ): array {
        $resolvedLanguage = strtolower((string) ($languageIso
            ?? $content?->language_iso
            ?? $space->settings->getDefaultLanguage()));

        if ($content) {
            return $this->contentI18nResolver
                ->resolve($space, $content, $resolvedLanguage, 'current')
                ->effectiveContent;
        }

        $canonicalId = $contentData['i18n_parent_id'] ?? null;

        if (! $canonicalId) {
            return [];
        }

        $canonical = Content::query()->find($canonicalId);

        if (! $canonical) {
            return [];
        }

        return $this->contentI18nResolver
            ->resolve($space, $canonical, $resolvedLanguage, 'current')
            ->effectiveContent;
    }
}
