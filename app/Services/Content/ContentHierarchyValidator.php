<?php

namespace App\Services\Content;

use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\Content;
use Illuminate\Validation\ValidationException;

class ContentHierarchyValidator
{
    public function validatePlacement(
        Space $space,
        Block $block,
        ?Content $parent = null,
        ?Content $content = null,
        ?string $languageIso = null,
    ): void {
        $parent?->loadMissing('block');

        if ($parent?->block?->type === 'single') {
            throw ValidationException::withMessages([
                'parent_id' => 'Single blocks cannot contain children.',
            ]);
        }

        if ($block->type !== 'single') {
            return;
        }

        if ($parent !== null) {
            throw ValidationException::withMessages([
                'parent_id' => 'Single blocks can only live at the root.',
            ]);
        }

        if ($content?->children()->whereNull('deleted_at')->exists()) {
            throw ValidationException::withMessages([
                'block_id' => 'Single blocks cannot keep children.',
            ]);
        }

        $resolvedLanguage = $languageIso ?: $content?->language_iso ?: $space->settings->getDefaultLanguage();

        $query = Content::query()
            ->whereNull('deleted_at')
            ->where('language_iso', $resolvedLanguage)
            ->where('block_id', $block->id);

        if ($content?->exists) {
            $query->whereKeyNot($content->id);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'block_id' => 'This single block already exists in the content tree.',
            ]);
        }
    }
}
