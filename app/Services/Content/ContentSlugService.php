<?php

namespace App\Services\Content;

use App\Models\Space\Content;
use App\Models\Space\Redirect;
use Illuminate\Support\Facades\Log;

class ContentSlugService
{
    /**
     * Update the full_slug for a content item.
     *
     * @param Content $content
     * @return string|null The old full_slug if it changed, null otherwise
     */
    public function updateFullSlug(Content $content): ?string
    {
        $oldFullSlug = $content->full_slug;

        if (empty($content->parent_id)) {
            $content->full_slug = '/' . $content->slug;
        } else {
            if (!$content->relationLoaded('parent')) {
                $content->load('parent');
            }

            if ($content->parent) {
                $content->full_slug = $content->parent->full_slug . '/' . $content->slug;
            } else {
                $content->full_slug =  '/' . $content->slug;
            }
        }

        return ($oldFullSlug !== $content->full_slug) ? $oldFullSlug : null;
    }

    /**
     * Create a redirect from old full_slug to new full_slug.
     *
     * @param string $oldFullSlug
     * @param string $newFullSlug
     * @return Redirect|null
     */
    public function createRedirect(string $oldFullSlug, string $newFullSlug): ?Redirect
    {
        if ($oldFullSlug === $newFullSlug || empty($oldFullSlug)) {
            return null;
        }

        $existingRedirect = Redirect::where('source', $oldFullSlug)
            ->where('target', $newFullSlug)
            ->first();

        if ($existingRedirect) {
            return $existingRedirect;
        }

        $redirect = Redirect::create([
            'source' => $oldFullSlug,
            'target' => $newFullSlug,
            'status_code' => 301,
        ]);

        // Delete potential reverse redirect to prevent loops
        $reverseRedirect = Redirect::where('source', $newFullSlug)
            ->where('target', $oldFullSlug)
            ->delete();

        return $redirect;
    }

    /**
     * Process a content item's children recursively to update their full_slugs.
     *
     * @param Content $content
     * @param bool $recursive Whether to process children recursively
     * @return array Statistics about the operations performed
     */
    public function processContentChildren(Content $content, bool $recursive = true): array
    {
        $stats = [
            'updated' => 0,
            'redirects_created' => 0,
            'errors' => 0,
        ];

        // Get all direct children
        $children = $content->children()->get();

        foreach ($children as $child) {
            try {
                // Update the child's full_slug
                $oldFullSlug = $this->updateFullSlug($child);

                // If the full_slug changed, save the child and create a redirect
                if ($oldFullSlug !== null) {
                    $child->save();
                    $stats['updated']++;

                    // Create redirect if needed
                    if (!empty($oldFullSlug)) {
                        $redirect = $this->createRedirect($oldFullSlug, $child->full_slug);
                        if ($redirect) {
                            $stats['redirects_created']++;
                        }
                    }
                }

                // Process this child's children if recursive
                if ($recursive) {
                    $childStats = $this->processContentChildren($child, true);

                    // Merge stats
                    $stats['updated'] += $childStats['updated'];
                    $stats['redirects_created'] += $childStats['redirects_created'];
                    $stats['errors'] += $childStats['errors'];
                }
            } catch (\Exception $e) {
                $stats['errors']++;
                Log::error("Error processing child content {$child->id}: " . $e->getMessage());
            }
        }

        return $stats;
    }
}
