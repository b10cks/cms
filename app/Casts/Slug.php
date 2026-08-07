<?php

namespace App\Casts;

use App\Services\Slug\Slugger;
use App\Services\Slug\SlugLanguage;
use Illuminate\Contracts\Database\Eloquent\CastsInboundAttributes;
use Illuminate\Database\Eloquent\Model;

class Slug implements CastsInboundAttributes
{
    /**
     * Prepare the given value for storage.
     *
     * Transliterated for the owning space's default language, so a German space
     * gets "groessen" rather than "grossen".
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        return app(Slugger::class)->forIdentifier(
            (string) $value,
            app(SlugLanguage::class)->forModel($model),
        );
    }
}
