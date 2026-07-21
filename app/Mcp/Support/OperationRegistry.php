<?php

declare(strict_types=1);

namespace App\Mcp\Support;

class OperationRegistry
{
    /**
     * Arguments that never fall back to the generic `id` argument.
     */
    private const NO_ID_FALLBACK = ['spaceId', 'teamId', 'userId', 'token', 'provider', 'accessToken'];

    /** @var array<string, array{description?: string, required?: list<string>, accepts?: list<string>, method: string, uri: string, authArg?: string}>|null */
    private static ?array $operations = null;

    /** @return array<string, array{description?: string, required?: list<string>, accepts?: list<string>, method: string, uri: string, authArg?: string}> */
    public static function all(): array
    {
        return self::$operations ??= require __DIR__.'/operations.php';
    }

    /** @return array{description?: string, required?: list<string>, accepts?: list<string>, method: string, uri: string, authArg?: string}|null */
    public static function find(string $name): ?array
    {
        return self::all()[$name] ?? null;
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::all());
    }

    /**
     * The operation list as exposed by the b10cks_mgmt_operations tool —
     * mirrors the TS server: name, description, required, accepts.
     *
     * @return list<array<string, mixed>>
     */
    public static function listing(): array
    {
        $listing = [];

        foreach (self::all() as $name => $operation) {
            $listing[] = array_filter([
                'name' => $name,
                'description' => $operation['description'] ?? null,
                'required' => $operation['required'] ?? null,
                'accepts' => $operation['accepts'] ?? null,
            ], fn (mixed $value): bool => $value !== null);
        }

        return $listing;
    }

    /**
     * Resolves an operation's URI template against the tool arguments.
     * `{blockId}`-style placeholders fall back to the generic `id` argument,
     * matching the TS server's argument helpers.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @throws \InvalidArgumentException when a required argument is missing
     */
    public static function resolveUri(string $template, array $arguments): string
    {
        return (string) preg_replace_callback('/\{(\w+)}/', function (array $matches) use ($arguments): string {
            $key = $matches[1];
            $value = $arguments[$key] ?? null;

            if (! is_string($value) || $value === '') {
                $value = in_array($key, self::NO_ID_FALLBACK, true) ? null : ($arguments['id'] ?? null);
            }

            if (! is_string($value) || $value === '') {
                throw new \InvalidArgumentException("Missing required string argument: {$key}");
            }

            return rawurlencode($value);
        }, $template);
    }
}
