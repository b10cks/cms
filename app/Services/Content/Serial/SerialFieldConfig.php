<?php

namespace App\Services\Content\Serial;

use App\Services\Content\Schema\BlockSchema;
use App\Services\Content\Schema\SchemaField;

/**
 * The normalized `serial` options of one schema field.
 */
class SerialFieldConfig
{
    public const string DEFAULT_FORMAT = '{counter}';

    public const array ON_MOVE_MODES = ['keep', 'reallocate'];

    /**
     * @param  array<int, string>  $scope
     */
    public function __construct(
        public readonly string $key,
        public readonly string $format,
        public readonly array $scope,
        public readonly string $unique,
        public readonly string $onMove,
        public readonly bool $editable,
    ) {}

    public static function fromField(SchemaField $field): self
    {
        $scope = $field->getAttribute('scope');
        $scope = is_array($scope)
            ? array_values(array_filter($scope, static fn (mixed $dimension): bool => is_string($dimension)))
            : ScopeKeyBuilder::DEFAULT_SCOPE;

        $unique = (string) $field->getAttribute('unique', 'scope');
        $onMove = (string) $field->getAttribute('on_move', 'keep');

        return new self(
            key: $field->getKey(),
            format: (string) ($field->getAttribute('format') ?: self::DEFAULT_FORMAT),
            scope: $scope === [] ? ScopeKeyBuilder::DEFAULT_SCOPE : $scope,
            unique: in_array($unique, ScopeKeyBuilder::UNIQUE_MODES, true) ? $unique : 'scope',
            onMove: in_array($onMove, self::ON_MOVE_MODES, true) ? $onMove : 'keep',
            editable: (bool) $field->getAttribute('editable', false),
        );
    }

    /**
     * Every serial field of a block, keyed by field key.
     *
     * @return array<string, array{config: self, field: SchemaField}>
     */
    public static function collect(BlockSchema $schema): array
    {
        $fields = [];

        foreach ($schema->getFields() as $key => $field) {
            if ($field->getType() !== 'serial') {
                continue;
            }

            $fields[(string) $key] = [
                'config' => self::fromField($field),
                'field' => $field,
            ];
        }

        return $fields;
    }
}
