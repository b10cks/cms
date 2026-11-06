<?php

namespace App\Services\Documentation;

/**
 * Compact notation for JSON an inferred resource cannot describe, used by the
 * schema catalogs and the doc annotations (`shape=`, `@apiQuery`).
 *
 * Tokens: string, integer, number, boolean, date-time, any, object;
 * `enum:a|b`; `@Name` for a component schema; `map:<token>` for an object with
 * free keys. `[]` makes a list and a trailing `?` makes it nullable. A token may
 * end in ` -- description`. Arrays: a one-element list is a list of that shape,
 * anything else is an object whose `?` key makes it nullable and whose `#` key
 * describes it.
 */
final class SchemaShape
{
    /** @return array<string, mixed> */
    public static function expand(string|array $shape): array
    {
        return \is_array($shape) ? self::expandArray($shape) : self::expandToken($shape);
    }

    /**
     * @param  array<mixed>  $shape
     * @return array<string, mixed>
     */
    private static function expandArray(array $shape): array
    {
        if (array_is_list($shape)) {
            return ['type' => 'array', 'items' => self::expand($shape[0])];
        }

        $schema = ['type' => 'object'];

        if (isset($shape['#'])) {
            $schema['description'] = $shape['#'];
        }

        if (isset($shape['?'])) {
            $schema['nullable'] = true;
        }

        $schema['properties'] = array_map(self::expand(...), array_diff_key($shape, ['#' => true, '?' => true]));

        return $schema;
    }

    /** @return array<string, mixed> */
    private static function expandToken(string $token): array
    {
        [$token, $description] = array_pad(explode(' -- ', $token, 2), 2, null);
        $nullable = str_ends_with($token, '?');
        $token = rtrim($token, '?');

        $schema = self::base($token);

        if ($nullable) {
            // OpenAPI 3.0 ignores siblings of a $ref, so a nullable reference needs allOf.
            $schema = isset($schema['$ref']) ? ['allOf' => [$schema], 'nullable' => true] : [...$schema, 'nullable' => true];
        }

        if ($description !== null) {
            $schema = isset($schema['$ref']) ? ['allOf' => [$schema], 'description' => $description] : [...$schema, 'description' => $description];
        }

        return $schema;
    }

    /** @return array<string, mixed> */
    private static function base(string $token): array
    {
        return match (true) {
            str_starts_with($token, 'map:') => ['type' => 'object', 'additionalProperties' => self::expand(substr($token, 4))],
            str_ends_with($token, '[]') => ['type' => 'array', 'items' => self::expand(substr($token, 0, -2))],
            str_starts_with($token, 'enum:') => ['type' => 'string', 'enum' => explode('|', substr($token, 5))],
            str_starts_with($token, '@') => ['$ref' => '#/components/schemas/'.substr($token, 1)],
            $token === 'date-time' => ['type' => 'string', 'format' => 'date-time'],
            $token === 'any' => [],
            $token === 'object' => ['type' => 'object', 'additionalProperties' => true],
            \in_array($token, ['string', 'integer', 'number', 'boolean'], true) => ['type' => $token],
            default => throw new \InvalidArgumentException("Unknown schema token: {$token}"),
        };
    }
}
