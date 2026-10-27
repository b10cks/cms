<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\Skills\SkillParser;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SkillParserTest extends TestCase
{
    private const VALID = "---\nname: brand-voice\ndescription: Writes in our voice.\n---\n\nBe brief.";

    #[Test]
    public function it_parses_a_valid_skill_with_supporting_files(): void
    {
        $skill = (new SkillParser)->parseUpload([
            ['path' => 'SKILL.md', 'content' => self::VALID],
            ['path' => 'examples/good.md', 'content' => 'Short and warm.'],
        ]);

        $this->assertSame('brand-voice', $skill->name);
        $this->assertSame(['examples/good.md'], $skill->supportingFiles());
    }

    /**
     * @param  list<array{path: string, content: string}>  $files
     */
    #[Test]
    #[DataProvider('invalidSkills')]
    public function it_rejects_an_invalid_skill_with_a_field_error(array $files, string $field): void
    {
        try {
            (new SkillParser)->parseUpload($files);
            $this->fail('The skill should be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }

    public static function invalidSkills(): array
    {
        return [
            'bad path' => [[['path' => 'SKILL.md', 'content' => self::VALID], ['path' => '../secrets.md', 'content' => 'x']], 'files.1.path'],
            'duplicate file' => [[['path' => 'SKILL.md', 'content' => self::VALID], ['path' => 'skill.md', 'content' => 'x']], 'files.1.path'],
            'missing name' => [[['path' => 'SKILL.md', 'content' => "---\ndescription: No name.\n---\nBody"]], 'name'],
            'oversize' => [[['path' => 'SKILL.md', 'content' => self::VALID], ['path' => 'big.md', 'content' => str_repeat('a', SkillParser::MAX_FILE_BYTES + 1)]], 'files.1.content'],
        ];
    }
}
