<?php

namespace App\Actions\DataEntry;

use App\Models\Space\DataEntry;
use App\Models\Space\DataSource;
use App\Services\Space\ShapeValue;
use Illuminate\Support\Facades\Cache;

/** Saves validated data entry attributes; shaped values are stored JSON-encoded. */
class UpdateDataEntry
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(DataEntry $entry, DataSource $dataSource, array $data): DataEntry
    {
        $entry->fill(self::encode($dataSource, $data));
        $entry->save();

        Cache::forget("data_source:{$dataSource->id}:entries");

        return $entry;
    }

    /**
     * JSON-encode structured value and dimension overrides for shaped sources.
     * A cleared (null) value is stored as an empty string — the column is
     * not nullable — and decoded back to null on output.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function encode(DataSource $dataSource, array $data): array
    {
        if ($dataSource->hasShape()) {
            if (array_key_exists('value', $data)) {
                $data['value'] = ShapeValue::encode($data['value'], $dataSource->shape);
            }

            if (! empty($data['dimensions'])) {
                $data['dimensions'] = array_map(
                    fn ($value) => ShapeValue::encode($value, $dataSource->shape),
                    $data['dimensions']
                );
            }
        }

        if (array_key_exists('value', $data)) {
            $data['value'] ??= '';
        }

        return $data;
    }
}
