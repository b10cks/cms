<?php

namespace App\Services\Content;

use App\Models\Management\Space;
use App\Models\Space\Content;

class ContentI18nValidator
{
    public function __construct(
        private readonly ContentI18nService $contentI18nService,
        private readonly ChildContentRuleService $childContentRuleService,
    ) {}

    public function validate(
        Space $space,
        array $data,
        ?Content $content = null,
        array $submittedChildSettingKeys = [],
    ): array
    {
        $errors = [];
        $defaultLanguage = $space->settings->getDefaultLanguage();
        $languageIso = strtolower((string) ($data['language_iso'] ?? $content?->language_iso ?? $defaultLanguage));
        $i18nParentId = array_key_exists('i18n_parent_id', $data)
            ? $data['i18n_parent_id']
            : $content?->i18n_parent_id;
        $override = data_get($data, 'settings.i18n_mode_override');
        $currentCanonicalId = $content
            ? $this->contentI18nService->getCanonicalId($content)
            : ($i18nParentId ?: null);
        $canonicalId = $i18nParentId ?: $currentCanonicalId;

        if (! \in_array($languageIso, $space->settings->getEnabledLanguages(), true)) {
            $errors['language_iso'] = 'The selected language must be enabled for this space.';
        }

        if ($languageIso === $defaultLanguage) {
            if ($i18nParentId !== null) {
                $errors['i18n_parent_id'] = 'The default language row must remain the canonical page in its family.';
            }
        } elseif ($i18nParentId === null) {
            $errors['i18n_parent_id'] = 'Non-default language rows must belong to a canonical page family.';
        }

        if ($i18nParentId !== null) {
            $canonical = Content::query()
                ->where('id', $i18nParentId)
                ->whereNull('deleted_at')
                ->first();

            if ($canonical) {
                if ($canonical->i18n_parent_id !== null) {
                    $errors['i18n_parent_id'] = 'Translations must point at the canonical default-language row.';
                }

                if ($canonical->language_iso !== $defaultLanguage) {
                    $errors['i18n_parent_id'] = 'Translations must belong to the default-language canonical row.';
                }
            }
        }

        if ($override !== null && $override !== 'inherit' && $i18nParentId !== null) {
            $errors['settings.i18n_mode_override'] = 'Only the canonical page may override the i18n mode.';
        }

        if ($i18nParentId !== null && $submittedChildSettingKeys !== []) {
            $canonicalSettings = Content::query()
                ->where('id', $i18nParentId)
                ->whereNull('deleted_at')
                ->first()
                ?->settings?->toArray() ?? [];
            $submittedSettings = $data['settings'] ?? [];

            foreach ($submittedChildSettingKeys as $key) {
                // Only reject a genuine change; a translation may echo the
                // canonical's value unchanged (e.g. the frontend resends it).
                if ($this->childSettingDiffers($submittedSettings[$key] ?? null, $canonicalSettings[$key] ?? null)) {
                    $errors["settings.{$key}"] = 'Only the canonical page may update child content settings.';
                }
            }
        }

        if ($canonicalId !== null) {
            $duplicateExists = Content::query()
                ->whereNull('deleted_at')
                ->where('language_iso', $languageIso)
                ->when($content?->id, fn ($query) => $query->where('id', '!=', $content->id))
                ->where(function ($query) use ($canonicalId) {
                    $query->where('id', $canonicalId)
                        ->orWhere('i18n_parent_id', $canonicalId);
                })
                ->exists();

            if ($duplicateExists) {
                $errors['language_iso'] = 'A content family cannot contain more than one row for the same language.';
            }
        }

        foreach ($this->childContentRuleService->validateSettings($data['settings'] ?? []) as $path => $message) {
            $errors[$path] = $message;
        }

        return $errors;
    }

    private function childSettingDiffers(mixed $submitted, mixed $canonical): bool
    {
        if (\is_array($submitted) && \is_array($canonical)) {
            sort($submitted);
            sort($canonical);

            return $submitted !== $canonical;
        }

        // Loose comparison so an unset canonical (null) matches a default-y
        // submitted value (false/''/[]) without flagging a phantom change.
        return $submitted != $canonical;
    }
}
