<?php

namespace App\Services;

class MentionExtractor
{
    public static function extractMentions(string $body): array
    {
        $mentions = [];
        $pattern = '/@([a-z0-9]{26})/i';

        if (preg_match_all($pattern, $body, $matches)) {
            $mentions = array_unique($matches[1]);
        }

        return array_values($mentions);
    }
}
