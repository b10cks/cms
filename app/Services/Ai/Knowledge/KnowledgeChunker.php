<?php

namespace App\Services\Ai\Knowledge;

/**
 * Splits markdown into heading-delimited chunks for retrieval. Long sections
 * are split again at paragraph breaks so one chunk stays a readable excerpt.
 */
final class KnowledgeChunker
{
    public const MAX_CHARS = 2000;

    /**
     * @return list<array{heading_path: string, text: string, position: int}>
     */
    public function chunk(string $markdown): array
    {
        $sections = [];
        $path = [];
        $buffer = [];
        $inFence = false;

        $flush = function () use (&$sections, &$path, &$buffer): void {
            $text = trim(implode("\n", $buffer));
            if ($text !== '') {
                $sections[] = ['heading_path' => implode(' > ', array_filter($path)), 'text' => $text];
            }
            $buffer = [];
        };

        foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
            if (preg_match('/^\s*(```|~~~)/', $line)) {
                $inFence = ! $inFence;
            }

            if (! $inFence && preg_match('/^(#{1,6})\s+(.+?)\s*#*\s*$/', $line, $matches)) {
                $flush();
                $level = \strlen($matches[1]);
                $path = \array_slice($path, 0, $level - 1);
                $path = array_pad($path, $level - 1, '');
                $path[$level - 1] = $matches[2];

                continue;
            }

            $buffer[] = $line;
        }
        $flush();

        $chunks = [];
        foreach ($sections as $section) {
            foreach ($this->split($section['text']) as $text) {
                $chunks[] = [
                    'heading_path' => mb_substr($section['heading_path'], 0, 500),
                    'text' => $text,
                    'position' => \count($chunks),
                ];
            }
        }

        return $chunks;
    }

    /**
     * @return list<string>
     */
    private function split(string $text): array
    {
        if (mb_strlen($text) <= self::MAX_CHARS) {
            return [$text];
        }

        $parts = [];
        $current = '';

        foreach (preg_split('/\R{2,}/', $text) ?: [] as $paragraph) {
            if ($current !== '' && mb_strlen($current) + mb_strlen($paragraph) + 2 > self::MAX_CHARS) {
                $parts[] = $current;
                $current = '';
            }

            while (mb_strlen($paragraph) > self::MAX_CHARS) {
                $parts[] = mb_substr($paragraph, 0, self::MAX_CHARS);
                $paragraph = mb_substr($paragraph, self::MAX_CHARS);
            }

            $current = $current === '' ? $paragraph : $current."\n\n".$paragraph;
        }

        if (trim($current) !== '') {
            $parts[] = $current;
        }

        return $parts;
    }
}
