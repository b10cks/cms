<?php

namespace App\Services\IconData;

use App\DTOs\ImportExport\ImportResult;
use App\Enums\IconImportMode;
use App\Models\Management\Space;
use App\Models\Space\Icon;
use App\Services\Icon\IconSvgParser;
use App\Services\ImportExport\Exceptions\ImportValidationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Imports an Iconify JSON icon set (the `{ prefix, icons: { name: { body } } }`
 * format produced by the Iconify tooling / API) into a space's icon registry.
 *
 * Every icon body is run through IconSvgParser — the same hardened sanitizer the
 * single-icon upload uses — so untrusted markup can never reach the DB.
 */
class IconDataImportService
{
    /**
     * Hard cap on the number of icons accepted in a single import, to bound the
     * work done inside the request.
     */
    private const MAX_ICONS = 5000;

    public function __construct(
        private readonly IconSvgParser $parser,
    ) {}

    public function importIcons(Space $space, UploadedFile $file, IconImportMode $mode): ImportResult
    {
        $data = $this->decode($file);

        $iconSet = $data['icons'];
        $defaultWidth = $this->positiveInt($data['width'] ?? null, 24);
        $defaultHeight = $this->positiveInt($data['height'] ?? null, 24);
        $tagsByName = $this->buildCategoryTags($data['categories'] ?? []);

        $successes = [];
        $changes = [];
        $deleted = [];
        $errors = [];

        (new Icon())->getConnection()->transaction(function () use (
            $iconSet, $defaultWidth, $defaultHeight, $tagsByName, $mode,
            &$successes, &$changes, &$deleted, &$errors,
        ): void {
            if ($mode === IconImportMode::Replacement) {
                $deleted = $this->pruneExistingIcons();
            }

            foreach ($iconSet as $sourceName => $definition) {
                try {
                    $prepared = $this->prepareIcon(
                        (string) $sourceName,
                        \is_array($definition) ? $definition : [],
                        $defaultWidth,
                        $defaultHeight,
                        $tagsByName,
                    );
                } catch (\Throwable $e) {
                    $errors[] = ['id' => (string) $sourceName, 'message' => $e->getMessage()];

                    continue;
                }

                // In replacement mode everything was just pruned, so always insert.
                $existing = $mode === IconImportMode::Addition
                    ? Icon::query()->where('key', $prepared['key'])->first()
                    : null;

                if ($existing) {
                    $diff = $this->applyUpdate($existing, $prepared);
                    if ($diff !== []) {
                        $changes[] = ['id' => $existing->id, 'key' => $existing->key, 'changes' => $diff];
                    } else {
                        $successes[] = ['id' => $existing->id, 'key' => $existing->key];
                    }

                    continue;
                }

                $icon = new Icon();
                $icon->fill($prepared);
                $icon->save();
                $successes[] = ['id' => $icon->id, 'key' => $icon->key];
            }
        });

        return new ImportResult(
            successes: $successes,
            changes: $changes,
            errors: $errors,
            deleted: $deleted,
        );
    }

    /**
     * Decode and validate the uploaded file as an Iconify icon set.
     *
     * @return array{icons: array<string, mixed>, width?: mixed, height?: mixed, categories?: mixed, prefix?: mixed}
     */
    private function decode(UploadedFile $file): array
    {
        $contents = file_get_contents($file->getRealPath());

        if ($contents === false || trim($contents) === '') {
            throw new ImportValidationException('The uploaded file is empty.');
        }

        $data = json_decode($contents, true);

        if (json_last_error() !== JSON_ERROR_NONE || !\is_array($data)) {
            throw new ImportValidationException('The file is not valid JSON.');
        }

        if (!isset($data['icons']) || !\is_array($data['icons']) || $data['icons'] === []) {
            throw new ImportValidationException(
                'This does not look like an Iconify icon set — no "icons" object was found.'
            );
        }

        if (\count($data['icons']) > self::MAX_ICONS) {
            throw new ImportValidationException(
                sprintf('The set contains %d icons; at most %d can be imported at once.', \count($data['icons']), self::MAX_ICONS)
            );
        }

        return $data;
    }

    /**
     * Build a name → [tags] map from the Iconify `categories` object, which maps
     * a category name to the list of icons in it.
     *
     * @param  mixed  $categories
     * @return array<string, array<int, string>>
     */
    private function buildCategoryTags(mixed $categories): array
    {
        if (!\is_array($categories)) {
            return [];
        }

        $tags = [];

        foreach ($categories as $category => $names) {
            if (!\is_string($category) || !\is_array($names)) {
                continue;
            }

            foreach ($names as $name) {
                if (\is_string($name)) {
                    $tags[$name][] = Str::limit($category, 50, '');
                }
            }
        }

        return $tags;
    }

    /**
     * Sanitize and normalise one source icon into a persistable attribute array.
     *
     * @param  array<string, mixed>  $definition
     * @param  array<string, array<int, string>>  $tagsByName
     * @return array{key: string, name: string, body: string, width: int, height: int, tags: array<int, string>}
     */
    private function prepareIcon(
        string $sourceName,
        array $definition,
        int $defaultWidth,
        int $defaultHeight,
        array $tagsByName,
    ): array {
        $body = $definition['body'] ?? null;

        if (!\is_string($body) || trim($body) === '') {
            throw new ImportValidationException('Icon has no "body".');
        }

        $key = Str::slug($sourceName);

        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $key)) {
            throw new ImportValidationException("\"{$sourceName}\" is not a valid icon name.");
        }

        $width = $this->positiveInt($definition['width'] ?? null, $defaultWidth);
        $height = $this->positiveInt($definition['height'] ?? null, $defaultHeight);

        // Wrap the Iconify body in a full <svg> so the shared parser can sanitize
        // it and hoist inherited attributes, exactly like a single upload.
        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d">%s</svg>',
            $width,
            $height,
            $body,
        );

        $parsed = $this->parser->parse($svg);

        return [
            'key' => $key,
            'name' => Str::limit(Str::headline($key), 100, ''),
            'body' => $parsed['body'],
            'width' => $parsed['width'],
            'height' => $parsed['height'],
            'tags' => array_values(array_unique($tagsByName[$sourceName] ?? [])),
        ];
    }

    /**
     * Apply an overwrite to an existing icon, returning the list of changed
     * fields (empty when nothing changed).
     *
     * @param  array{key: string, name: string, body: string, width: int, height: int, tags: array<int, string>}  $prepared
     * @return array<int, array{field: string, old: mixed, new: mixed}>
     */
    private function applyUpdate(Icon $icon, array $prepared): array
    {
        $diff = [];

        foreach (['body', 'width', 'height'] as $field) {
            if ($icon->{$field} != $prepared[$field]) {
                $diff[] = ['field' => $field, 'old' => $icon->{$field}, 'new' => $prepared[$field]];
                $icon->{$field} = $prepared[$field];
            }
        }

        // Only add category tags; never drop tags a user curated by hand.
        $mergedTags = array_values(array_unique([...($icon->tags ?? []), ...$prepared['tags']]));
        if ($mergedTags !== ($icon->tags ?? [])) {
            $diff[] = ['field' => 'tags', 'old' => $icon->tags, 'new' => $mergedTags];
            $icon->tags = $mergedTags;
        }

        if ($diff !== []) {
            $icon->save();
        }

        return $diff;
    }

    /**
     * Soft-delete every existing icon, returning identifiers for the summary.
     *
     * @return array<int, array{id: string, key: string}>
     */
    private function pruneExistingIcons(): array
    {
        $deleted = [];

        Icon::query()->select(['id', 'key'])->chunkById(500, function ($icons) use (&$deleted): void {
            foreach ($icons as $icon) {
                $deleted[] = ['id' => $icon->id, 'key' => $icon->key];
                $icon->delete();
            }
        });

        return $deleted;
    }

    private function positiveInt(mixed $value, int $default): int
    {
        $int = (int) $value;

        return $int > 0 ? $int : $default;
    }
}
