<?php

namespace Tests\Feature;

use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

class ContentVersionRelationsTest extends TestCase
{
    use LazilyRefreshDatabase;
    use SpaceTestingTrait;

    #[Test]
    public function it_extracts_relation_ids_from_reference_fields_when_saving_versions(): void
    {
        $space = Space::factory()->withLive()->create();
        $this->setUpSpaceTesting($space);
        app()->instance('currentSpace', $space);

        $pageBlock = Block::query()->create([
            'external_id' => (string) Str::uuid(),
            'name' => 'Page',
            'slug' => 'page',
            'type' => 'root',
            'schema' => [
                'featured' => [
                    'type' => 'reference',
                ],
                'related' => [
                    'type' => 'references',
                ],
                'sections' => [
                    'type' => 'blocks',
                ],
            ],
        ]);
        Block::query()->create([
            'external_id' => (string) Str::uuid(),
            'name' => 'Teaser',
            'slug' => 'teaser',
            'type' => 'nestable',
            'schema' => [
                'linked' => [
                    'type' => 'references',
                ],
            ],
        ]);

        $firstRelationId = strtolower((string) Str::ulid());
        $secondRelationId = strtolower((string) Str::ulid());
        $thirdRelationId = strtolower((string) Str::ulid());

        $content = new Content;
        $content->forceFill([
            'block_id' => $pageBlock->id,
            'name' => 'Home',
            'slug' => 'home',
            'full_slug' => '/home',
            'language_iso' => 'en',
            'settings' => [],
        ]);
        $content->id = strtolower((string) Str::ulid());

        $version = ContentVersion::createWithContentContext([
            'content_id' => $content->id,
            'content' => [
                'featured' => [$firstRelationId],
                'related' => [$secondRelationId, $firstRelationId],
                'sections' => [
                    [
                        'block' => 'teaser',
                        'linked' => [$thirdRelationId, $secondRelationId],
                    ],
                ],
            ],
        ], $content->setRelation('block', $pageBlock));

        $this->assertSame(
            [$firstRelationId, $secondRelationId, $thirdRelationId],
            $version->relation_ids,
        );
    }
}
