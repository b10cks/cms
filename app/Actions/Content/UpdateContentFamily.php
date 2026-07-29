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

class UpdateContentFamily
{
    public function __construct(
        private readonly UpdateContent $updateContent,
        private readonly ContentI18nService $contentI18nService,
        private readonly ContentMutationPayloadValidator $payloadValidator,
    ) {}

    public function execute(array $data, Content $content, Space $space, Authenticatable|User|null $owner): Content
    {
        $translations = array_values($data['translations'] ?? []);

        if ($translations === []) {
            $this->updateContent->execute($data, $content, $space, $owner);

            return $content->fresh()->load(['block', 'parent', 'i18n_parent', 'i18n_children', 'i18n_siblings', 'current_version']);
        }

        $familyCanonicalId = $this->contentI18nService->getCanonicalId($content);
        $primaryPayload = Arr::except($data, ['translations']);

        $content->getConnection()->transaction(function () use ($primaryPayload, $translations, $content, $space, $owner, $familyCanonicalId): void {
            $this->updateContent->execute($primaryPayload, $content, $space, $owner);

            $family = $this->contentI18nService->getFamily($content)->keyBy('language_iso');
            $family->put($content->language_iso, $content->fresh());

            foreach ($translations as $index => $translation) {
                $target = $this->resolveTranslationTarget($translation, $familyCanonicalId, $family->all());

                if (! $target) {
                    throw ValidationException::withMessages([
                        "translations.{$index}.language_iso" => ['The selected translation does not exist in this content family.'],
                    ]);
                }

                try {
                    $validatedPayload = $this->payloadValidator->validate($translation, $target);
                    unset($validatedPayload['id']);
                    $this->updateContent->execute($validatedPayload, $target, $space, $owner);
                    $family->put($target->language_iso, $target->fresh());
                } catch (ValidationException $e) {
                    throw $this->prefixValidationException($e, "translations.{$index}.");
                }
            }
        });

        return $content->fresh()->load(['block', 'parent', 'i18n_parent', 'i18n_children', 'i18n_siblings', 'current_version']);
    }

    /**
     * @param  array<string, mixed>  $translation
     * @param  array<string, Content>  $familyByLanguage
     */
    private function resolveTranslationTarget(array $translation, string $familyCanonicalId, array $familyByLanguage): ?Content
    {
        if (isset($translation['id']) && is_string($translation['id'])) {
            $target = Content::query()
                ->where('id', $translation['id'])
                ->whereNull('deleted_at')
                ->first();

            if (! $target) {
                return null;
            }

            return $this->contentI18nService->getCanonicalId($target) === $familyCanonicalId
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
