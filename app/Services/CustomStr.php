<?php

namespace App\Services;

use App\Services\Slug\Slugger;

class CustomStr extends \Str
{
    /**
     * Kept for the callers that predate {@see Slugger} and have no language to
     * offer. New code should resolve a language and call the Slugger directly —
     * without one, umlauts fold ("ü" -> "u") instead of expanding ("ü" -> "ue").
     *
     * The `$dictionary` argument is ignored: symbol expansion is now part of the
     * shared rules so the frontend can reproduce it. No caller ever passed one.
     */
    public static function slug($title, $separator = '-', $language = 'en', $dictionary = ['@' => 'at'])
    {
        return app(Slugger::class)->make((string) $title, $language, $separator);
    }
}
