<?php

namespace App\Services\Search;

use App\Actions\Content\TransformContentToSearchable;
use App\Contracts\Search\SearchDriverInterface;
use App\Models\Management\Space;
use App\Models\Space\Content;
use GuzzleHttp\Client;
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

        $searchableText = $this->transformer->execute($content);
        $indexName = $this->getIndexName($space);

        try {
            $this->client->put("{$indexName}/_doc/{$content->id}", [
                'json' => [
                    'id' => $content->id,
                    'name' => $content->name,
                    'slug' => $content->slug,
                    'full_slug' => $content->full_slug,
                    'language_iso' => $content->language_iso,
                    'block_id' => $content->block_id,
                    'published_at' => $content->published_at?->toIso8601String(),
                    'searchable_content' => $searchableText,
                ]
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

        Content::whereNotNull('published_at')
            ->with('published_version')
            ->chunk(100, function ($contents) use ($space) {
                foreach ($contents as $content) {
                    $this->indexContent($content, $space);
                }
            });
    }

    protected function getIndexName(Space $space): string
    {
        return "space_{$space->id}";
    }

    public function search(Space $space, string $query, string $language, int $limit = 20, int $offset = 0): array
    {
        if (empty(trim($query))) {
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
                        'match' => [
                            'language_iso' => $language,
                            'searchable_content' => [
                                'query' => $query,
                                'operator' => 'or'
                            ]
                        ]
                    ],
                    'sort' => [
                        '_score' => ['order' => 'desc']
                    ]
                ]
            ]);

            $body = json_decode($response->getBody()->getContents(), true);
            $hits = $body['hits'] ?? [];
            $total = $hits['total']['value'] ?? 0;

            $results = array_map(function ($hit) {
                $source = $hit['_source'];
                return [
                    'id' => $source['id'],
                    'name' => $source['name'],
                    'slug' => $source['slug'],
                    'full_slug' => $source['full_slug'],
                    'language_iso' => $source['language_iso'],
                    'block_id' => $source['block_id'],
                    'published_at' => $source['published_at'],
                    'relevance_score' => $hit['_score'],
                ];
            }, $hits['hits'] ?? []);

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
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}
