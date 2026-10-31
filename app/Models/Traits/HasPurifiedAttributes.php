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

    /**
     * Purifies on write only and returns the stored value on read. Query
     * builder writes skip mutators, so they must pass rows through purifyRow().
     * The default keeps Eloquent's Attribute-method scan from failing on it.
     */
    protected function makeWritePurifiedAttribute(string $configKey = 'removeAll'): Attribute
    {
        return Attribute::set(fn (?string $value) => $this->purifyValue($value, $configKey));
    }

    /**
     * Runs raw column values through the model's set mutators, so query
     * builder writes store what an Eloquent save would.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function purifyRow(array $row): array
    {
        $model = new static;

        foreach ($row as $key => $value) {
            if ((\is_string($value) || $value === null) && $model->hasAttributeSetMutator($key)) {
                $row[$key] = $model->setAttribute($key, $value)->getAttributes()[$key];
            }
        }

        return $row;
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
