<?php

namespace App\Actions\Content;

use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Models\User;
use App\Services\Content\ContentI18nService;
use App\Services\Content\ContentMutationPayloadValidator;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class CreateContentFamily
{
    public function __construct(
        private readonly CreateContent $createContent,
        private readonly ContentI18nService $contentI18nService,
        private readonly ContentMutationPayloadValidator $payloadValidator,
    ) {}

    public function execute(array $data, Space $space, Authenticatable|User|null $owner): Content
    {
        $translations = array_values($data['translations'] ?? []);
        $canonicalPayload = Arr::except($data, ['translations']);
        $canonical = new Content();

        \DB::transaction(function () use ($canonicalPayload, $translations, $canonical, $space, $owner): void {
            $this->createContent->execute($canonicalPayload, $canonical, $space, $owner);

            foreach ($translations as $index => $translation) {
                $translationPayload = $this->buildTranslationCreatePayload($translation, $canonical);

                try {
                    $validatedPayload = $this->payloadValidator->validate($translationPayload);
                    $this->assertCreateFieldsPresent($validatedPayload);
                    $this->createContent->execute($validatedPayload, new Content(), $space, $owner);
                } catch (ValidationException $e) {
                    throw $this->prefixValidationException($e, "translations.{$index}.");
                }
            }
        });

        return $canonical->load(['block', 'parent', 'i18n_parent', 'i18n_children', 'i18n_siblings', 'current_version']);
    }

    /**
     * @param  array<string, mixed>  $translation
     * @return array<string, mixed>
     */
    private function buildTranslationCreatePayload(array $translation, Content $canonical): array
    {
        $payload = Arr::except($translation, ['id', 'translations']);
        $payload['block_id'] ??= $canonical->block_id;
        $payload['i18n_parent_id'] = $canonical->id;

        if (! array_key_exists('parent_id', $payload)) {
            $payload['parent_id'] = $this->resolveParentIdForLanguage(
                $canonical->parent_id,
                (string) ($payload['language_iso'] ?? ''),
            );
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertCreateFieldsPresent(array $payload): void
    {
        $errors = [];

        // `slug` is deliberately not required: a translation without one gets
        // its slug composed by the create action — from the block's slug
        // pattern, or from its (translated) name.
        foreach (['name', 'block_id', 'language_iso'] as $field) {
            if (! array_key_exists($field, $payload) || $payload[$field] === null || $payload[$field] === '') {
                $errors[$field] = [sprintf('The %s field is required.', str_replace('_', ' ', $field))];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function resolveParentIdForLanguage(?string $parentId, string $languageIso): ?string
    {
        if ($parentId === null || $languageIso === '') {
            return $parentId;
        }

        $parent = Content::query()
            ->where('id', $parentId)
            ->whereNull('deleted_at')
            ->first();

        if (! $parent) {
            return $parentId;
        }

        $family = $this->contentI18nService->getFamily($parent);

        return $family->firstWhere('language_iso', $languageIso)?->id ?? $parentId;
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
