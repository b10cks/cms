<?php

namespace App\Services\Ai\Knowledge;

use LogicException;

/**
 * The knowledge and glossary policy one run or operation works under: resolved
 * terms including manual edits, and style and reference revisions. Prompts, tools
 * and checks all read from one of these, so a change to live knowledge reaches only
 * work started after it.
 *
 * `id` is null for an unstored view of the live state: callers without a run
 * (MCP) and runs started before snapshots existed.
 */
final readonly class PolicySnapshot
{
    /** 2 holds one row per meaning under `concepts`. 1 held a row per language under `terms`, read by {@see FrozenGlossaryRow}. */
    public const VERSION = 2;

    /**
     * @param  list<GlossaryConcept>  $concepts
     * @param  list<array{id: string, slug: string, title: string, type: string, locale: string|null, revision_id: string, revision: int, hash: string, markdown?: string}>  $documents  `markdown` for style guides, which enter prompts whole
     */
    private function __construct(
        public ?string $id,
        public string $fingerprint,
        public array $concepts,
        public array $documents,
    ) {}

    /**
     * @param  array<string, mixed>  $parts  concepts (as arrays) and documents, in any order
     */
    public static function fromParts(array $parts): self
    {
        $payload = self::normalize($parts);

        return self::fromPayload($payload, null, self::fingerprintOf($payload));
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws LogicException for a snapshot newer than this code
     */
    public static function fromPayload(array $payload, ?string $id, string $fingerprint): self
    {
        if (($payload['version'] ?? 0) > self::VERSION) {
            throw new LogicException("Policy snapshot version {$payload['version']} is newer than this code.");
        }

        return new self(
            $id,
            $fingerprint,
            GlossaryConcept::listFromFrozen($payload['concepts'] ?? $payload['terms'] ?? []),
            $payload['documents'] ?? [],
        );
    }

    /**
     * Rows alone, for callers that hold no resolved policy.
     *
     * @param  list<GlossaryConcept>  $concepts
     */
    public static function fromConcepts(array $concepts): self
    {
        return new self(null, '', $concepts, []);
    }

    public function withId(string $id): self
    {
        return new self($id, $this->fingerprint, $this->concepts, $this->documents);
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
            'concepts' => array_map(static fn (GlossaryConcept $concept) => $concept->toArray(), $this->concepts),
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
     * Rows a source text activates, each with its language that counts in the target
     * locale, the one whose Avoid wording the output must not hold. `spans` says where in
     * the text, and marks a match on Avoid wording.
     *
     * @return list<array{concept: GlossaryConcept, wording: GlossaryWording, target: GlossaryWording|null, spans: list<array{start: int, end: int, text: string, avoid: bool}>}>
     */
    public function activate(string $text, ?string $sourceLocale, ?string $targetLocale = null): array
    {
        $target = $targetLocale !== null && $targetLocale !== '' ? $targetLocale : null;

        return array_map(
            static fn (array $hit) => [...$hit, 'target' => $target !== null ? $hit['concept']->languageFor($target) : null],
            GlossaryMatcher::for($this->concepts)->find($text, $sourceLocale),
        );
    }

    /**
     * Provenance a proposal records: this snapshot and the rows it applied.
     *
     * @param  list<GlossaryConcept>  $concepts
     * @return array{snapshot_id: string|null, fingerprint: string, terms: list<array<string, string|null>>}
     */
    public function provenance(array $concepts): array
    {
        return [
            'snapshot_id' => $this->id,
            'fingerprint' => $this->fingerprint,
            'terms' => array_map(static fn (GlossaryConcept $concept) => [
                'concept_id' => $concept->id,
                'version' => self::versionOf($concept->toArray()),
            ], $concepts),
        ];
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
                $present[GlossaryMatcher::normalizeLocale($item['locale'])] = true;
            }
        }

        $selected = [];
        foreach ($locales as $locale) {
            $locale = GlossaryMatcher::normalizeLocale($locale);
            $base = explode('-', $locale)[0];
            $choice = isset($present[$locale]) ? $locale : (isset($present[$base]) ? $base : null);
            if ($choice !== null) {
                $selected[$choice] = true;
            }
        }

        return array_values(array_filter($items, static fn (array $item) => $item['locale'] === null || isset($selected[GlossaryMatcher::normalizeLocale($item['locale'])])));
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
            'concepts' => $by($parts['concepts'] ?? [], static function (array $row): string {
                $locale = array_key_first($row['languages']);

                return $locale.'|'.mb_strtolower((string) ($row['languages'][$locale]['use'] ?? $row['languages'][$locale]['avoid'][0] ?? '')).'|'.$row['id'];
            }),
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

    /**
     * @param  array<string, mixed>  $data
     */
    private static function versionOf(array $data): string
    {
        return hash('sha256', self::canonical($data));
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
