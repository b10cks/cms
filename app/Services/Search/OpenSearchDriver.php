<?php

namespace App\Services\Search;

use App\Actions\Content\TransformContentToSearchable;
use App\Contracts\Search\SearchDriverInterface;
use App\Models\Management\Space;
use App\Models\Space\Content;
use GuzzleHttp\Client;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class OpenSearchDriver implements SearchDriverInterface
{
    protected Client $client;

    public function __construct(
        protected TransformContentToSearchable $transformer
    ) {
        $this->client = new Client([
            'base_uri' => config('services.opensearch.host'),
            'auth' => [
                config('services.opensearch.username'),
                config('services.opensearch.password')
            ],
            'verify' => config('services.opensearch.verify_ssl', true),
            'timeout' => 30,
        ]);
    }

    public function indexContent(Content $content, Space $space): void
    {
        if (!$content->published_at) {
            return;
        }

        $document = $this->document($content, $space);
        $indexName = $this->getIndexName($space);

        try {
            $this->client->put("{$indexName}/_doc/{$content->id}", [
                'json' => $document,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to index content in OpenSearch', [
                'content_id' => $content->id,
                'space_id' => $space->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * One `_bulk` request for the whole collection: index actions for
     * published entries, delete actions for the rest. A missing document on
     * delete is not a failure.
     */
    public function syncContents(Collection $contents, Space $space): array
    {
        $indexName = $this->getIndexName($space);
        $failed = [];
        $lines = [];
        $sentIds = [];

        foreach ($contents as $content) {
            if (! $content->published_at) {
                $lines[] = json_encode(['delete' => ['_id' => $content->id]]);
                $sentIds[] = $content->id;

                continue;
            }

            try {
                $document = json_encode($this->document($content, $space), JSON_THROW_ON_ERROR);
            } catch (\Throwable $e) {
                report($e);
                $failed[$content->id] = $e->getMessage();

                continue;
            }

            $lines[] = json_encode(['index' => ['_id' => $content->id]]);
            $lines[] = $document;
            $sentIds[] = $content->id;
        }

        if ($sentIds === []) {
            return $failed;
        }

        try {
            $response = $this->client->post("{$indexName}/_bulk", [
                'headers' => ['Content-Type' => 'application/x-ndjson'],
                'body' => implode("\n", $lines)."\n",
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to bulk index content in OpenSearch', [
                'space_id' => $space->id,
                'count' => count($sentIds),
                'error' => $e->getMessage(),
            ]);

            return $failed + array_fill_keys($sentIds, $e->getMessage());
        }

        $body = json_decode($response->getBody()->getContents(), true);

        foreach ($body['items'] ?? [] as $item) {
            $operation = array_key_first($item);
            $result = $item[$operation];
            $status = (int) ($result['status'] ?? 500);

            if ($status < 300 || ($operation === 'delete' && $status === 404)) {
                continue;
            }

            $failed[(string) $result['_id']] = (string) ($result['error']['reason'] ?? "OpenSearch status {$status}");
        }

        return $failed;
    }

    /**
     * @return array<string, mixed>
     */
    private function document(Content $content, Space $space): array
    {
        return [
            'id' => $content->id,
            'name' => $content->name,
            'slug' => $content->slug,
            'full_slug' => $content->full_slug,
            'language_iso' => $content->language_iso,
            'block_id' => $content->block_id,
            'published_at' => $content->published_at?->toIso8601String(),
            'searchable_content' => $this->transformer->execute($content, $space),
        ];
    }

    public function removeContent(Content $content, Space $space): void
    {
        $indexName = $this->getIndexName($space);

        try {
            $this->client->delete("{$indexName}/_doc/{$content->id}");
        } catch (\Exception $e) {
            if (!str_contains($e->getMessage(), '404')) {
                Log::error('Failed to remove content from OpenSearch', [
                    'content_id' => $content->id,
                    'space_id' => $space->id,
                    'error' => $e->getMessage()
                ]);
                throw $e;
            }
        }
    }

    public function createIndex(Space $space): void
    {
        $indexName = $this->getIndexName($space);

        try {
            $this->client->get($indexName);
            return;
        } catch (\Exception $e) {
            if (!str_contains($e->getMessage(), '404')) {
                throw $e;
            }
        }

        try {
            $this->client->put($indexName, [
                'json' => [
                    'settings' => [
                        'number_of_shards' => 1,
                        'number_of_replicas' => 1,
                        'analysis' => [
                            'analyzer' => [
                                'default' => [
                                    'type' => 'standard'
                                ]
                            ]
                        ]
                    ],
                    'mappings' => [
                        'properties' => [
                            'id' => ['type' => 'keyword'],
                            'name' => ['type' => 'text'],
                            'slug' => ['type' => 'keyword'],
                            'full_slug' => ['type' => 'keyword'],
                            'language_iso' => ['type' => 'keyword'],
                            'block_id' => ['type' => 'keyword'],
                            'published_at' => ['type' => 'date'],
                            'searchable_content' => [
                                'type' => 'text',
                                'analyzer' => 'standard'
                            ]
                        ]
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to create OpenSearch index', [
                'space_id' => $space->id,
                'index_name' => $indexName,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function deleteIndex(Space $space): void
    {
        $indexName = $this->getIndexName($space);

        try {
            $this->client->delete($indexName);
        } catch (\Exception $e) {
            if (!str_contains($e->getMessage(), '404')) {
                Log::error('Failed to delete OpenSearch index', [
                    'space_id' => $space->id,
                    'index_name' => $indexName,
                    'error' => $e->getMessage()
                ]);
                throw $e;
            }
        }
    }

    public function reindexSpace(Space $space): void
    {
        $this->createIndex($space);

        Content::query()
            ->whereNotNull('contents.published_at')
            ->with(['i18n_parent', 'i18n_children', 'i18n_siblings', 'block', 'relations', 'assets', 'links'])
            ->select([
                'contents.*',
                'content_versions.content',
                'content_versions.relation_ids',
                'content_versions.asset_ids',
                'content_versions.link_ids'
            ])
            ->leftJoin('content_versions', 'contents.published_version_id', '=', 'content_versions.id')
            ->orderBy('contents.id')
            ->chunkById(100, function ($contents) use ($space) {
                foreach ($contents as $content) {
                    /** @var Content $content */
                    $this->indexContent($content, $space);
                }
            }, 'contents.id', 'id');
    }

    protected function getIndexName(Space $space): string
    {
        return "space_{$space->id}";
    }

    public function search(Space $space, string $query, string $language, int $limit = 20, int $offset = 0): array
    {
        $query = trim($query);

        if ($query === '') {
            return [
                'total' => 0,
                'results' => [],
            ];
        }

        $indexName = $this->getIndexName($space);

        try {
            $response = $this->client->post("{$indexName}/_search", [
                'json' => [
                    'from' => $offset,
                    'size' => $limit,
                    'query' => [
                        'bool' => [
                            'must' => [
                                [
                                    'match' => [
                                        'searchable_content' => [
                                            'query' => $query,
                                            'operator' => 'or',
                                        ],
                                    ],
                                ],
                            ],
                            'filter' => [
                                [
                                    'term' => [
                                        'language_iso' => $language,
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'sort' => [
                        [
                            '_score' => [
                                'order' => 'desc',
                            ],
                        ],
                        [
                            'published_at' => [
                                'order' => 'desc',
                                'unmapped_type' => 'date',
                            ],
                        ],
                    ],
                    '_source' => [
                        'includes' => ['id'],
                    ],
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);
            $hits = $body['hits'] ?? [];
            $total = (int) ($hits['total']['value'] ?? 0);

            $results = array_values(array_filter(array_map(
                fn(array $hit): ?array => isset($hit['_source']['id'])
                ? [
                    'id' => $hit['_source']['id'],
                    'relevance_score' => round((float) ($hit['_score'] ?? 0), 4),
                ]
                : null,
                $hits['hits'] ?? [],
            )));

            return [
                'total' => $total,
                'results' => $results,
            ];
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), '404')) {
                return [
                    'total' => 0,
                    'results' => [],
                ];
            }

            Log::error('Failed to search content in OpenSearch', [
                'space_id' => $space->id,
                'query' => $query,
                'language' => $language,
                'limit' => $limit,
                'offset' => $offset,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
