<?php

namespace App\Models\Traits;

use Illuminate\Database\Eloquent\Casts\Attribute;

trait HasPurifiedAttributes
{
    protected function makePurifiedAttribute(?string $configKey = null, ?string $setConfigKey = null): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $this->purifyValue($value, $configKey),
            set: fn (?string $value) => $this->purifyValue($value, $setConfigKey ?? $configKey)
        );
    }

    private array $allowedLetters = [
        '&amp;' => '&',
    ];

    protected function purifyValue(?string $dirty, ?string $configKey = null)
    {
        if (!$dirty) {
            return $dirty;
        }

        $clean = clean($dirty, $this->getPurifyConfig($configKey));

        return str_replace(
            array_keys($this->allowedLetters),
            array_values($this->allowedLetters),
            $clean
        );
    }

    protected function getPurifyConfig(?string $configKey = 'rteStrict')
    {
        return config("purifier.settings.{$configKey}", []);
    }
}
