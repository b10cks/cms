<?php

namespace App\Services\Content;

use Illuminate\Support\Collection;

class LinkHandler
{
    use ContentExtractor;
    use ContentReplacer;

    public function extractContentLinks(array $data): array
    {
        return $this->extractMatchingField($data, [
            'type' => 'internal',
        ], 'content');
    }

    public function replaceContentLinks(array $data, Collection $links): array
    {
        return $this->replaceMatching($data, [
            'type' => 'internal',
        ], function ($src) use ($links) {
            $link = $links->firstWhere('id', $src['content'] ?? null);
            if ($link) {
                $src = [
                    'url' => $link->full_slug,
                    'title' => $link->name,
                ] + $src;
            }

            return $src;
        });
    }
}
