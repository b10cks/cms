<?php

namespace App\Models\Traits;

trait TranslatedAttribute
{
    public function getJsonTranslatedValue(string $key, $default = null, array $replacements = [])
    {
        if ($fallbackTranslation = data_get($this->{$key}, 'key')) {
            return trans($fallbackTranslation, $replacements);
        }

        $result = data_get(
            $this->{$key},
            app()->getLocale() ?? app()->getFallbackLocale(),
            data_get(
                $this->{$key},
                app()->getFallbackLocale(),
                $default
            )
        );

        return app('translator')->makeReplacements($result, $replacements);
    }
}
