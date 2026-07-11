<?php

namespace Tests\Unit\Services\Content\Diff;

use App\Services\Content\Diff\ArrayDiffService;
use App\Services\Content\Diff\DiffType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ArrayDiffServiceTest extends TestCase
{
    private ArrayDiffService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ArrayDiffService();
    }

    private function doc(string $text): array
    {
        return [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [['type' => 'text', 'text' => $text]],
                ],
            ],
        ];
    }

    #[Test]
    public function itDetectsScalarChanges()
    {
        $result = $this->service->diff(['title' => 'Old'], ['title' => 'New']);

        $this->assertCount(1, $result->entries);
        $this->assertSame('title', $result->entries[0]->path);
        $this->assertSame(DiffType::CHANGED, $result->entries[0]->type);
        $this->assertSame('Old', $result->entries[0]->oldValue);
        $this->assertSame('New', $result->entries[0]->newValue);
    }

    #[Test]
    public function itDetectsAddedAndRemovedKeys()
    {
        $result = $this->service->diff(['removed' => 1], ['added' => 2]);

        $this->assertCount(2, $result->entries);
        $this->assertSame(DiffType::ADDED, $result->getChangesByType(DiffType::ADDED)[array_key_first($result->getChangesByType(DiffType::ADDED))]->type);
        $this->assertCount(1, $result->getChangesByType(DiffType::REMOVED));
    }

    #[Test]
    public function itFlattensNestedArraysIntoDottedPaths()
    {
        $result = $this->service->diff(
            ['seo' => ['title' => 'Old']],
            ['seo' => ['title' => 'New']]
        );

        $this->assertCount(1, $result->entries);
        $this->assertSame('seo.title', $result->entries[0]->path);
    }

    #[Test]
    public function itKeepsChangedRichTextDocsAsSingleEntry()
    {
        $old = ['body' => $this->doc('Hello world')];
        $new = ['body' => $this->doc('Hello brave new world')];

        $result = $this->service->diff($old, $new);

        $this->assertCount(1, $result->entries);
        $entry = $result->entries[0];
        $this->assertSame('body', $entry->path);
        $this->assertSame(DiffType::CHANGED, $entry->type);
        $this->assertSame($old['body'], $entry->oldValue);
        $this->assertSame($new['body'], $entry->newValue);
    }

    #[Test]
    public function itKeepsNestedRichTextDocsAsSingleEntry()
    {
        $old = ['blocks' => [['text' => $this->doc('One')]]];
        $new = ['blocks' => [['text' => $this->doc('Two')]]];

        $result = $this->service->diff($old, $new);

        $this->assertCount(1, $result->entries);
        $this->assertSame('blocks.0.text', $result->entries[0]->path);
        $this->assertSame($new['blocks'][0]['text'], $result->entries[0]->newValue);
    }

    #[Test]
    public function itEmitsFullDocForAddedAndRemovedRichTextFields()
    {
        $result = $this->service->diff(
            ['intro' => $this->doc('Bye')],
            ['body' => $this->doc('Hi')]
        );

        $this->assertCount(2, $result->entries);

        $added = array_values($result->getChangesByType(DiffType::ADDED))[0];
        $this->assertSame('body', $added->path);
        $this->assertSame($this->doc('Hi'), $added->newValue);

        $removed = array_values($result->getChangesByType(DiffType::REMOVED))[0];
        $this->assertSame('intro', $removed->path);
        $this->assertSame($this->doc('Bye'), $removed->oldValue);
    }

    #[Test]
    public function itIgnoresUnchangedRichTextDocs()
    {
        $doc = ['body' => $this->doc('Same'), 'title' => 'Old'];
        $result = $this->service->diff($doc, [...$doc, 'title' => 'New']);

        $this->assertCount(1, $result->entries);
        $this->assertSame('title', $result->entries[0]->path);
    }
}
