<?php

namespace Tests\Unit\Services\Content\Diff;

use App\Services\Content\Diff\DiffEntry;
use App\Services\Content\Diff\DiffType;
use App\Services\Content\Diff\SchemaAwareDiffService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SchemaAwareDiffServiceTest extends TestCase
{
    private SchemaAwareDiffService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SchemaAwareDiffService();
    }

    private const SCHEMA = [
        'title' => ['type' => 'text'],
        'cta' => ['type' => 'link'],
        'tags' => ['type' => 'options'],
        'gallery' => ['type' => 'multiAsset'],
        'body' => ['type' => 'blocks'],
        'specs' => ['type' => 'table'],
    ];

    private const TEASER_SCHEMA = [
        'headline' => ['type' => 'text'],
        'image' => ['type' => 'asset'],
    ];

    private function diff(array $old, array $new): array
    {
        $result = $this->service->diff($old, $new, self::SCHEMA, fn (string $slug): array => match ($slug) {
            'teaser' => self::TEASER_SCHEMA,
            default => [],
        });

        return $result->entries;
    }

    private function entry(array $entries, string $path): DiffEntry
    {
        foreach ($entries as $entry) {
            if ($entry->path === $path) {
                return $entry;
            }
        }

        $this->fail("No diff entry for path '{$path}'");
    }

    #[Test]
    public function itAnnotatesScalarChangesWithFieldType()
    {
        $entries = $this->diff(['title' => 'Old'], ['title' => 'New']);

        $this->assertCount(1, $entries);
        $this->assertSame(DiffType::CHANGED, $entries[0]->type);
        $this->assertSame('text', $entries[0]->fieldType);
    }

    #[Test]
    public function itKeepsLinkFieldsAtomic()
    {
        $old = ['cta' => ['type' => 'url', 'url' => 'https://a.test', 'target' => '_blank']];
        $new = ['cta' => ['type' => 'email', 'email' => 'x@y.test']];

        $entries = $this->diff($old, $new);

        $this->assertCount(1, $entries);
        $this->assertSame('cta', $entries[0]->path);
        $this->assertSame('link', $entries[0]->fieldType);
        $this->assertSame($old['cta'], $entries[0]->oldValue);
        $this->assertSame($new['cta'], $entries[0]->newValue);
    }

    #[Test]
    public function itKeepsOptionAndAssetArraysAtomicAndCanonicalizesAliases()
    {
        $entries = $this->diff(
            ['tags' => ['a', 'b'], 'gallery' => [['id' => 'a1', 'filename' => 'x.jpg']]],
            ['tags' => ['a', 'c'], 'gallery' => []]
        );

        $this->assertSame('multi_assets', $this->entry($entries, 'gallery')->fieldType);
        $this->assertSame('options', $this->entry($entries, 'tags')->fieldType);
        $this->assertSame(['a', 'c'], $this->entry($entries, 'tags')->newValue);
    }

    #[Test]
    public function itAlignsNestedBlockItemsByIdAcrossInsertions()
    {
        $teaser = fn (string $id, string $headline) => [
            'id' => $id, 'block' => 'teaser', 'headline' => $headline,
        ];

        $old = ['body' => [$teaser('one', 'First'), $teaser('two', 'Second')]];
        // a new item is inserted at the front AND item "two" is edited
        $new = ['body' => [$teaser('zero', 'Inserted'), $teaser('one', 'First'), $teaser('two', 'Edited')]];

        $entries = $this->diff($old, $new);

        $this->assertCount(2, $entries);

        $added = $this->entry($entries, 'body.0');
        $this->assertSame(DiffType::ADDED, $added->type);
        $this->assertSame('block', $added->fieldType);

        $changed = $this->entry($entries, 'body.2.headline');
        $this->assertSame(DiffType::CHANGED, $changed->type);
        $this->assertSame('text', $changed->fieldType);
        $this->assertSame('Second', $changed->oldValue);
        $this->assertSame('Edited', $changed->newValue);
    }

    #[Test]
    public function itEmitsWholeItemWhenBlockSlugChanges()
    {
        $old = ['body' => [['id' => 'one', 'block' => 'teaser', 'headline' => 'x']]];
        $new = ['body' => [['id' => 'one', 'block' => 'hero', 'title' => 'y']]];

        $entries = $this->diff($old, $new);

        $this->assertCount(1, $entries);
        $this->assertSame('body.0', $entries[0]->path);
        $this->assertSame('block', $entries[0]->fieldType);
        $this->assertSame(DiffType::CHANGED, $entries[0]->type);
    }

    #[Test]
    public function itFallsBackToPositionalFlattenWhenItemsLackIds()
    {
        $old = ['body' => [['block' => 'teaser', 'headline' => 'x']]];
        $new = ['body' => [['block' => 'teaser', 'headline' => 'y']]];

        $entries = $this->diff($old, $new);

        $this->assertCount(1, $entries);
        $this->assertSame('body.0.headline', $entries[0]->path);
        $this->assertNull($entries[0]->fieldType);
    }

    #[Test]
    public function itReportsRemovedBlockItems()
    {
        $old = ['body' => [
            ['id' => 'one', 'block' => 'teaser', 'headline' => 'Keep'],
            ['id' => 'two', 'block' => 'teaser', 'headline' => 'Drop'],
        ]];
        $new = ['body' => [['id' => 'one', 'block' => 'teaser', 'headline' => 'Keep']]];

        $entries = $this->diff($old, $new);

        $this->assertCount(1, $entries);
        $this->assertSame('body.1', $entries[0]->path);
        $this->assertSame(DiffType::REMOVED, $entries[0]->type);
        $this->assertSame('block', $entries[0]->fieldType);
    }

    #[Test]
    public function itIgnoresPureBlockReorders()
    {
        $items = [
            ['id' => 'one', 'block' => 'teaser', 'headline' => 'A'],
            ['id' => 'two', 'block' => 'teaser', 'headline' => 'B'],
        ];

        $entries = $this->diff(['body' => $items], ['body' => array_reverse($items)]);

        $this->assertCount(0, $entries);
    }

    #[Test]
    public function itDetectsRichTextDocsInUnknownFields()
    {
        $doc = fn (string $text) => ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]],
        ]];

        $entries = $this->diff(['legacy' => $doc('a')], ['legacy' => $doc('b')]);

        $this->assertCount(1, $entries);
        $this->assertSame('richtext', $entries[0]->fieldType);
    }

    #[Test]
    public function itFlattensUnknownArrayFieldsLikeLegacyDiff()
    {
        $entries = $this->diff(
            ['misc' => ['a' => ['b' => 1]]],
            ['misc' => ['a' => ['b' => 2]]]
        );

        $this->assertCount(1, $entries);
        $this->assertSame('misc.a.b', $entries[0]->path);
        $this->assertNull($entries[0]->fieldType);
    }

    #[Test]
    public function itHandlesAddedAndRemovedFields()
    {
        $entries = $this->diff(['title' => 'Gone'], ['cta' => ['type' => 'url', 'url' => 'https://a.test']]);

        $removed = $this->entry($entries, 'title');
        $this->assertSame(DiffType::REMOVED, $removed->type);
        $this->assertSame('text', $removed->fieldType);

        $added = $this->entry($entries, 'cta');
        $this->assertSame(DiffType::ADDED, $added->type);
        $this->assertSame('link', $added->fieldType);
    }

    #[Test]
    public function itSerializesFieldTypeInEntryPayload()
    {
        $entries = $this->diff(['title' => 'Old'], ['title' => 'New']);

        $this->assertSame('text', $entries[0]->toArray()['field_type']);
    }
}
