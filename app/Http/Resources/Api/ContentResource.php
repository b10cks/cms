<?php

namespace App\Http\Resources\Api;

use App\Models\Space\Content;
use App\Services\Content\AssetHandler;
use App\Services\Content\ContentI18nResolver;
use App\Services\Content\LinkHandler;
use App\Services\Content\ResolvedContent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContentResource extends JsonResource
{
    public function toArray(Request $request)
    {
        $resolved = $this->resolveContent($request);
        $row = $resolved->targetContent ?? $resolved->fallbackContent ?? $resolved->canonicalContent;
        $row->loadMissing('block');

        $this->additional([
            'rv' => app('currentSpace')->rv,
        ]);

        return [
            'id' => $row->getRouteKey(),
            'name' => $row->name,
            'slug' => $row->slug,
            'block' => $row->block?->slug,
            'parent_id' => $row->parent_id,
            'full_slug' => $row->full_slug,
            'content' => $this->getTransformedContent($resolved),
            'language_iso' => $resolved->requestedLanguage,
            'translations' => $this->handleTranslations($resolved, $row),
            'published_at' => $row->published_at?->toIso8601String(),
            'first_published_at' => $row->first_published_at?->toIso8601String(),
            'created_at' => $row->created_at?->toIso8601String(),
            'updated_at' => $row->updated_at?->toIso8601String(),
        ];
    }

    protected function resolveContent(Request $request): ResolvedContent
    {
        if ($this->resource instanceof ResolvedContent) {
            return $this->resource;
        }

        /** @var Content $content */
        $content = $this->resource;
        $versionScope = $request->input('vid', 'published');

        return app(ContentI18nResolver::class)->resolve(
            app('currentSpace'),
            $content,
            $content->language_iso,
            $versionScope === 'draft' ? 'current' : $versionScope,
        );
    }

    protected function getTransformedContent(ResolvedContent $resolved): array|\stdClass
    {
        $content = $resolved->effectiveContent;
        if (! $content) {
            return new \stdClass;
        }

        $this->injectData($resolved, $content);

        return [
            ...$content,
            'block' => ($resolved->targetContent ?? $resolved->fallbackContent ?? $resolved->canonicalContent)
                ->loadMissing('block')
                ->block
                ?->slug,
        ];
    }

    protected function handleTranslations(ResolvedContent $resolved, Content $currentRow)
    {
        return SimpleContentResource::collection(
            $resolved->familyContents
                ->filter(fn (Content $content) => $content->published_at !== null && $content->id !== $currentRow->id)
                ->values()
        );
    }

    protected function injectData(ResolvedContent $resolved, array &$content)
    {
        $content = app(LinkHandler::class)->replaceContentLinks($content, $resolved->effectiveLinks);
        $assetContext = $resolved->targetContent ?? $resolved->fallbackContent ?? $resolved->canonicalContent;
        $content = app(AssetHandler::class)->replaceContentAssets($assetContext, $content, $resolved->effectiveAssets);
    }
}
