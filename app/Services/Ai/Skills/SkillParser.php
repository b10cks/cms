<?php

namespace App\Services\Ai\Skills;

use Illuminate\Validation\ValidationException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Parses and validates a skill folder. Errors are field errors keyed by file
 * (`files.<index>.path`, `files.<index>.content`) or by frontmatter key.
 */
final class SkillParser
{
    public const ENTRY = 'SKILL.md';

    public const MAX_FILES = 20;

    public const MAX_FILE_BYTES = 65_536;

    public const MAX_SKILL_BYTES = 262_144;

    public const OUTPUTS = [SkillManifest::OUTPUT_TEXT, SkillManifest::OUTPUT_RICHTEXT, SkillManifest::OUTPUT_JSON];

    private const SURFACE_PATTERN = '/^(canvas|content-tree|selection|cmdk|meta-tags|translate|asset|learning|field:[a-z_-]+|bulk:[a-z_-]+)$/';

    private const BUDGET_KEYS = ['max_steps', 'max_tokens', 'max_usd'];

    /**
     * @param  list<array{path: string, content: string}>  $files
     *
     * @throws ValidationException
     */
    public function parseUpload(array $files): SkillManifest
    {
        $errors = [];
        $map = [];
        $seen = [];

        if (\count($files) > self::MAX_FILES) {
            $errors['files'][] = 'A skill may hold at most '.self::MAX_FILES.' files.';
        }

        foreach ($files as $index => $file) {
            $path = (string) ($file['path'] ?? '');
            $content = (string) ($file['content'] ?? '');

            if (! self::isValidPath($path)) {
                $errors["files.{$index}.path"][] = 'Use a relative path with letters, digits, dots, dashes and slashes only.';

                continue;
            }

            $key = strtolower($path);
            if (isset($seen[$key])) {
                $errors["files.{$index}.path"][] = "The file {$path} is listed twice.";

                continue;
            }
            $seen[$key] = true;

            if (! mb_check_encoding($content, 'UTF-8')) {
                $errors["files.{$index}.content"][] = 'Files must be UTF-8 text.';
            } elseif (\strlen($content) > self::MAX_FILE_BYTES) {
                $errors["files.{$index}.content"][] = 'Each file may be at most 64 KB.';
            }

            $map[$path] = $content;
        }

        if (array_sum(array_map('strlen', $map)) > self::MAX_SKILL_BYTES) {
            $errors['files'][] = 'A skill may be at most 256 KB in total.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $this->parse($map);
    }

    /**
     * Parse a validated path => content map.
     *
     * @param  array<string, string>  $files
     *
     * @throws ValidationException
     */
    public function parse(array $files): SkillManifest
    {
        if (! isset($files[self::ENTRY])) {
            throw ValidationException::withMessages(['files' => ['A skill needs a SKILL.md file.']]);
        }

        [$meta, $body] = $this->splitFrontmatter($files[self::ENTRY]);
        $b10cks = \is_array($meta['b10cks'] ?? null) ? $meta['b10cks'] : [];
        $errors = [];

        $name = $meta['name'] ?? null;
        if (! \is_string($name) || ! preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $name) || \strlen($name) > 64) {
            $errors['name'][] = 'The frontmatter needs a name in lowercase letters, digits and dashes (max 64).';
        }

        $description = $meta['description'] ?? null;
        if (! \is_string($description) || trim($description) === '' || mb_strlen($description) > 1024) {
            $errors['description'][] = 'The frontmatter needs a description (max 1024 characters).';
        }

        $surfaces = $this->stringList($b10cks['surfaces'] ?? []);
        foreach ($surfaces as $surface) {
            if (! preg_match(self::SURFACE_PATTERN, $surface)) {
                $errors['b10cks.surfaces'][] = "Unknown surface: {$surface}.";
            }
        }

        $output = $b10cks['output'] ?? SkillManifest::OUTPUT_TEXT;
        $schema = null;
        if (\is_string($output) && str_ends_with($output, '.json')) {
            $schema = isset($files[$output]) ? json_decode($files[$output], true) : null;
            if (! \is_array($schema)) {
                $errors['b10cks.output'][] = "The output schema {$output} is missing or not valid JSON.";
            }
            $output = SkillManifest::OUTPUT_JSON;
        } elseif (! \in_array($output, self::OUTPUTS, true)) {
            $errors['b10cks.output'][] = 'Output must be text, richtext, json or a JSON schema file.';
        }

        $budget = \is_array($b10cks['budget'] ?? null) ? array_intersect_key($b10cks['budget'], array_flip(self::BUDGET_KEYS)) : [];
        foreach ($budget as $key => $value) {
            if (! is_numeric($value) || $value <= 0) {
                $errors["b10cks.budget.{$key}"][] = 'Budgets must be positive numbers.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return new SkillManifest(
            name: $name,
            description: trim($description),
            instructions: trim($body),
            surfaces: $surfaces,
            blocks: $this->stringList($b10cks['blocks'] ?? []),
            tools: isset($b10cks['tools']) ? $this->stringList($b10cks['tools']) : null,
            output: $output,
            schema: $schema,
            config: \is_string($b10cks['config'] ?? null) ? $b10cks['config'] : null,
            budget: array_map(static fn ($value) => $value + 0, $budget),
            files: $files,
        );
    }

    public static function isValidPath(string $path): bool
    {
        return $path !== ''
            && \strlen($path) <= 200
            && preg_match('#^[A-Za-z0-9_-][A-Za-z0-9._-]*(/[A-Za-z0-9_-][A-Za-z0-9._-]*)*$#', $path) === 1
            && ! str_contains($path, '..');
    }

    /**
     * @return array{0: array<string, mixed>, 1: string}
     *
     * @throws ValidationException
     */
    private function splitFrontmatter(string $markdown): array
    {
        if (! preg_match('/\A---\R(.*?)\R---\R?(.*)\z/s', ltrim($markdown, "\u{FEFF}"), $matches)) {
            throw ValidationException::withMessages(['name' => ['SKILL.md must start with a frontmatter block (---).']]);
        }

        try {
            $meta = Yaml::parse($matches[1]);
        } catch (ParseException $e) {
            throw ValidationException::withMessages(['frontmatter' => ['The frontmatter is not valid YAML: '.$e->getMessage()]]);
        }

        return [\is_array($meta) ? $meta : [], $matches[2]];
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        return \is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }
}
