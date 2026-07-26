<?php

namespace App\Http\Controllers\Mgmt\Content;

use App\Http\Controllers\Controller;
use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Services\Content\Serial\ContentSerialAssigner;
use App\Services\Content\Serial\ContentSlugComposer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The values a new entry of a block would receive for its generated fields.
 *
 * Deliberately a peek and not a reservation: allocating when a create dialog
 * opens would burn a number every time someone changes their mind. The preview
 * can therefore go stale if another editor creates an entry in the same scope
 * first — the entry shows its real value immediately after it is created.
 */
class ContentSerialPreviewController extends Controller
{
    public function __invoke(
        Request $request,
        Space $space,
        ContentSerialAssigner $assigner,
        ContentSlugComposer $slugComposer,
    ): JsonResponse {
        $this->authorize('create', [Content::class, $space]);

        $validated = $request->validate([
            'block_id' => ['required', 'string'],
            'parent_id' => ['nullable', 'string'],
            'language_iso' => ['nullable', 'string', 'max:5'],
            'name' => ['nullable', 'string', 'max:100'],
            'i18n_parent_id' => ['nullable', 'string'],
            'except_content_id' => ['nullable', 'string'],
        ]);

        /** @var Block $block */
        $block = Block::query()->findOrFail($validated['block_id']);

        $parent = ! empty($validated['parent_id'])
            ? Content::query()->with('current_version')->whereNull('deleted_at')->find($validated['parent_id'])
            : null;

        $languageIso = $validated['language_iso'] ?? $space->settings->getDefaultLanguage();

        // A translation carries the canonical entry's serials rather than
        // drawing its own, so its preview reads them instead of peeking at the
        // counter — same rule the create action applies.
        $canonical = ! empty($validated['i18n_parent_id'])
            ? Content::query()->with('current_version')->whereNull('deleted_at')->find($validated['i18n_parent_id'])
            : null;

        $values = $canonical
            ? $assigner->previewForTranslation($block, $canonical)
            : $assigner->preview($space, $block, $parent, $languageIso);

        $name = trim((string) ($validated['name'] ?? ''));

        $fields = [];

        foreach ($values as $key => $value) {
            $fields[$key] = [
                'value' => $value,
                'preview' => $canonical === null,
            ];
        }

        // Rendered through the same composer the create action uses, so what the
        // dialog shows is what the entry will get — including the `-2` suffix
        // when a sibling already owns the slug. `except_content_id` lets an
        // existing translation regenerate its slug without colliding with itself.
        $slugPreview = $name !== ''
            ? $slugComposer->uniqueAmongSiblings(
                $slugComposer->compose($block, $parent, $languageIso, $values, $name),
                $parent?->id,
                $languageIso,
                $validated['except_content_id'] ?? null,
            )
            : null;

        return response()->json([
            'fields' => $fields,
            'slug_pattern' => $block->settings->hasCustomSlugPattern()
                ? $block->settings->getSlugPattern()
                : null,
            'slug_preview' => $slugPreview,
        ]);
    }
}
