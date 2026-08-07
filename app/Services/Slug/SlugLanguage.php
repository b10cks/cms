<?php

namespace App\Services\Slug;

use App\Support\SpaceContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Decides which language a slug should be transliterated for.
 *
 * Content carries its own `language_iso`; everything else (data sources, spaces,
 * storages, icons) is a space-level identifier, so it inherits the space's
 * default language. Falls back to English rather than throwing — a slug is never
 * important enough to fail a request over.
 */
class SlugLanguage
{
    /**
     * The language a model's slug should use.
     *
     * A model can answer for itself by declaring `slugLanguage()`, which is how
     * a Space picks its own default language instead of the ambient one — during
     * space creation there is no ambient space yet.
     */
    public function forModel(Model $model): ?string
    {
        if (method_exists($model, 'slugLanguage')) {
            return $model->slugLanguage();
        }

        return $this->current();
    }

    /**
     * The default language of the space currently in context, if any.
     */
    public function current(): ?string
    {
        $space = request('space') ?? SpaceContext::current();

        if ($space === null) {
            return null;
        }

        return data_get($space->settings, 'default_language');
    }
}
