<?php

namespace App\Services\Ai\Translation;

/** Change set items for one translation proposal, with what to store once they are recorded. */
final readonly class PreparedTranslation
{
    /**
     * @param  list<array<string, mixed>>  $items  change set item attributes, in record order
     * @param  list<list<array{field_path: string, source_text: string, target_text: string, reason: string, previous_source_text: string|null}>>  $lineage  per item, its units
     */
    public function __construct(
        public TranslationDestination $destination,
        public array $items,
        public array $lineage,
    ) {}

    /**
     * The same translation without the fields a person holds.
     *
     * @param  array<string, string>  $left  path => conflict item ID
     */
    public function without(array $left): self
    {
        $kept = array_keys(array_filter($this->items, fn (array $item) => ! isset($left[$item['path'] ?? ''])));
        $pick = fn (array $list) => array_values(array_intersect_key($list, array_flip($kept)));

        return new self($this->destination, $pick($this->items), $pick($this->lineage));
    }

    /**
     * The units of the fields a person holds.
     *
     * @param  array<string, string>  $left  path => conflict item ID
     * @return list<string>
     */
    public function unitsOf(array $left): array
    {
        $units = [];
        foreach ($this->items as $index => $item) {
            if (isset($left[$item['path'] ?? ''])) {
                array_push($units, ...array_column($this->lineage[$index] ?? [], 'field_path'));
            }
        }

        return $units;
    }
}
