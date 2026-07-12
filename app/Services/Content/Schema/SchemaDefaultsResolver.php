<?php

namespace App\Services\Content\Schema;

class SchemaDefaultsResolver
{
    /**
     * Resolve the initial content payload for a block schema from the
     * per-field `default` attributes.
     *
     * Only meaningful defaults are seeded: the block editor persists the
     * type's natural empty value (`''`, `0`, `false`, `[]`) as `default` for
     * every field, and seeding those would be indistinguishable from an
     * empty payload while bloating the stored content.
     *
     * @return array<string, mixed>
     */
    public function resolve(BlockSchema $schema): array
    {
        $defaults = [];

        foreach ($schema->getFields() as $key => $field) {
            $value = $this->resolveFieldDefault($field);

            if ($value !== null) {
                $defaults[$key] = $value;
            }
        }

        return $defaults;
    }

    protected function resolveFieldDefault(SchemaField $field): mixed
    {
        if ($field->getType() === 'date' && (bool) $field->getAttribute('use_current_as_default')) {
            return $this->currentDateForFormat((string) $field->getAttribute('format', 'date'));
        }

        $value = $field->getAttribute('default');

        if ($value === null || $value === '' || $value === [] || $value === false || $value === 0) {
            return null;
        }

        if ($field->getType() === 'table' && is_array($value) && empty($value['rows'])) {
            return null;
        }

        return $value;
    }

    protected function currentDateForFormat(string $format): string
    {
        return match ($format) {
            'time' => now()->format('H:i'),
            'datetime-local' => now()->format('Y-m-d\TH:i'),
            default => now()->format('Y-m-d'),
        };
    }
}
