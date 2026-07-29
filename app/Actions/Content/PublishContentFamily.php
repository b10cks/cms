<?php

namespace App\Actions\Content;

use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Models\User;
use App\Services\Content\ContentI18nService;
use App\Services\Content\ContentMutationPayloadValidator;
use App\Services\Search\SearchService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class PublishContentFamily
{
    public function __construct(
        private readonly PublishContent $publishContent,
        private readonly ContentI18nService $contentI18nService,
        private readonly ContentMutationPayloadValidator $payloadValidator,
        private readonly SearchService $searchService,
    ) {}

    public function execute(array $data, Content $content, Space $space, Authenticatable|User|null $owner): Content
    {
        $canonical = $this->contentI18nService->getCanonicalContent($content);
        $translations = array_values($data['translations'] ?? []);

        if ($translations === []) {
            $this->publishContent->execute($data, $content, $space, $owner);

            return $content->fresh()->load(['block', 'parent', 'i18n_parent', 'i18n_children', 'i18n_siblings', 'current_version']);
        }

        $publishedIds = [];
        $canonicalPayload = Arr::except($data, ['translations']);

        $canonical->getConnection()->transaction(function () use (
            $canonical,
            $canonicalPayload,
            $translations,
            $space,
            $owner,
            &$publishedIds,
        ): void {
            $this->publishContent->executeWithoutIndex($canonicalPayload, $canonical, $space, $owner);
            $publishedIds[] = $canonical->id;

            $family = $this->contentI18nService->getFamily($canonical)->keyBy('language_iso');

            foreach ($translations as $index => $translation) {
                $target = $this->resolveTranslationTarget($translation, $canonical, $family->all());

                if (! $target) {
                    throw ValidationException::withMessages([
                        "translations.{$index}.language_iso" => ['The selected translation does not exist in this content family.'],
                    ]);
                }

                try {
                    $validatedPayload = $this->payloadValidator->validate($translation, $target, true);
                    unset($validatedPayload['id']);
                    $this->publishContent->executeWithoutIndex($validatedPayload, $target, $space, $owner);
                    $publishedIds[] = $target->id;
                    $family->put($target->language_iso, $target->fresh());
                } catch (ValidationException $e) {
                    throw $this->prefixValidationException($e, "translations.{$index}.");
                }
            }
        });

        foreach (array_values(array_unique($publishedIds)) as $publishedId) {
            $publishedContent = Content::query()->find($publishedId);

            if (! $publishedContent) {
                continue;
            }

            $this->searchService->indexContent($publishedContent->load('published_version'), $space);
        }

        return $canonical->fresh()->load(['block', 'parent', 'i18n_parent', 'i18n_children', 'i18n_siblings', 'current_version']);
    }

    /**
     * @param  array<string, mixed>  $translation
     * @param  array<string, Content>  $familyByLanguage
     */
    private function resolveTranslationTarget(array $translation, Content $canonical, array $familyByLanguage): ?Content
    {
        if (isset($translation['id']) && is_string($translation['id'])) {
            $target = Content::query()
                ->where('id', $translation['id'])
                ->whereNull('deleted_at')
                ->first();

            if (! $target) {
                return null;
            }

            return $this->contentI18nService->getCanonicalId($target) === $canonical->id
                ? $target
                : null;
        }

        $languageIso = strtolower((string) ($translation['language_iso'] ?? ''));

        return $familyByLanguage[$languageIso] ?? null;
    }

    private function prefixValidationException(ValidationException $exception, string $prefix): ValidationException
    {
        $errors = [];

        foreach ($exception->errors() as $key => $messages) {
            $errors[$prefix . $key] = $messages;
        }

        return ValidationException::withMessages($errors);
    }
}
