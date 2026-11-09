<?php

namespace App\Services\Ai\Knowledge;

use LogicException;

/**
 * The knowledge policy one run or operation works under: style and reference
 * revisions. Prompts and tools read from one of these, so a change to live
 * knowledge reaches only work started after it.
 *
 * `id` is null for an unstored view of the live state: callers without a run
 * (MCP) and runs started before snapshots existed.
 */
final readonly class PolicySnapshot
{
    /** 3 holds documents only. 2 also held `concepts`, which are ignored now. 1 is no longer read. */
    public const VERSION = 3;

    private const READABLE = [2, 3];

    /**
     * @param  list<array{id: string, slug: string, title: string, description?: string, type: string, locale: string|null, revision_id: string, revision: int, hash: string, markdown?: string}>  $documents  `markdown` for style guides, which enter prompts whole; `description` is missing in snapshots from before it existed
     */
    private function __construct(
        public ?string $id,
        public string $fingerprint,
        public array $documents,
    ) {}

    /**
     * @param  array<string, mixed>  $parts  documents, in any order
     */
    public static function fromParts(array $parts): self
    {
        $payload = self::normalize($parts);

        return self::fromPayload($payload, null, self::fingerprintOf($payload));
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws LogicException for a snapshot of a version this code does not read
     */
    public static function fromPayload(array $payload, ?string $id, string $fingerprint): self
    {
        $version = $payload['version'] ?? 0;
        if (! \in_array($version, self::READABLE, true)) {
            throw new LogicException("Policy snapshot version {$version} is not read by this code, which reads version ".self::VERSION.'.');
        }

        return new self($id, $fingerprint, $payload['documents'] ?? []);
    }

    /** `de_AT` and `DE-at` are the same locale. */
    public static function normalizeLocale(string $locale): string
    {
        return strtolower(str_replace('_', '-', trim($locale)));
    }

    public function withId(string $id): self
    {
        return new self($id, $this->fingerprint, $this->documents);
    }

    public function isPinned(): bool
    {
        return $this->id !== null;
    }

    /**
     * What a run or operation stores to find this snapshot again.
     *
     * @return array{id: string|null, fingerprint: string, version: int}
     */
    public function reference(): array
    {
        return ['id' => $this->id, 'fingerprint' => $this->fingerprint, 'version' => self::VERSION];
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'version' => self::VERSION,
            'documents' => $this->documents,
        ];
    }

    /**
     * Style guides for the locales being written; null means every locale.
     *
     * @param  list<string>|null  $locales
     * @return list<array<string, mixed>>
     */
    public function styles(?array $locales): array
    {
        return self::forLocales(array_values(array_filter($this->documents, static fn (array $document) => $document['type'] === 'style')), $locales);
    }

    /**
     * @param  list<string>|null  $locales
     * @return list<array<string, mixed>>
     */
    public function references(?array $locales): array
    {
        return self::forLocales(array_values(array_filter($this->documents, static fn (array $document) => $document['type'] === 'reference')), $locales);
    }

    /**
     * The frozen descriptors of this policy's reference documents, by revision. A
     * rename or deletion of the live document does not reach these.
     *
     * @return array<string, array{document_id: string, slug: string, title: string, locale: string|null, revision: int, hash: string}>
     */
    public function referenceSources(): array
    {
        $sources = [];
        foreach ($this->references(null) as $document) {
            $sources[$document['revision_id']] = [
                'document_id' => $document['id'],
                'slug' => $document['slug'],
                'title' => $document['title'],
                'locale' => $document['locale'],
                'revision' => $document['revision'],
                'hash' => $document['hash'],
            ];
        }

        return $sources;
    }

    /**
     * For each locale: the items of exactly that locale, else of its base language.
     * Items without a locale apply everywhere. A sibling region never stands in.
     *
     * @template T of array{locale: string|null}
     *
     * @param  list<T>  $items
     * @param  list<string>|null  $locales
     * @return list<T>
     */
    private static function forLocales(array $items, ?array $locales): array
    {
        if ($locales === null) {
            return $items;
        }

        $present = [];
        foreach ($items as $item) {
            if ($item['locale'] !== null) {
                $present[self::normalizeLocale($item['locale'])] = true;
            }
        }

        $selected = [];
        foreach ($locales as $locale) {
            $locale = self::normalizeLocale($locale);
            $base = explode('-', $locale)[0];
            $choice = isset($present[$locale]) ? $locale : (isset($present[$base]) ? $base : null);
            if ($choice !== null) {
                $selected[$choice] = true;
            }
        }

        return array_values(array_filter($items, static fn (array $item) => $item['locale'] === null || isset($selected[self::normalizeLocale($item['locale'])])));
    }

    /**
     * @param  array<string, mixed>  $parts
     * @return array<string, mixed>
     */
    private static function normalize(array $parts): array
    {
        $by = static function (array $rows, callable $key): array {
            usort($rows, static fn (array $a, array $b) => strcmp($key($a), $key($b)));

            return $rows;
        };

        return [
            'version' => self::VERSION,
            'documents' => $by($parts['documents'] ?? [], static fn (array $row) => "{$row['type']}|{$row['slug']}"),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function fingerprintOf(array $payload): string
    {
        return hash('sha256', self::canonical($payload));
    }

    /** Key order must not change a hash: MySQL JSON columns reorder object keys. */
    private static function canonical(mixed $value): string
    {
        return json_encode(self::sorted($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private static function sorted(mixed $value): mixed
    {
        if (! \is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::sorted(...), $value);
    }
}
