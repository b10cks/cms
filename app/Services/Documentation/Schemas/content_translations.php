<?php

/**
 * Response shapes of the translation view endpoints in ContentTranslationController,
 * in SchemaShape notation.
 */
return [
    'ContentTranslationStatus' => [
        'locale' => 'string',
        'source_locale' => 'string',
        'translation_id' => 'string? -- Null while the locale has no translation entry.',
        'units' => 'map:@ContentTranslationUnitStatus -- Keyed by unit ID: `title`, `body.<block id>.text`, `seo.title`.',
    ],
    'ContentTranslationUnitStatus' => [
        'status' => 'enum:missing|empty|stale|same_as_source|current|inherited|not_in_translation',
        'previous_source' => 'string? -- stale only: the source the translation was written against.',
    ],
    'ContentTranslationAssist' => [
        'unit' => 'string',
        'type' => 'string -- Field type of the unit.',
        'source' => 'string -- Plain text; rich text drops its markup.',
        'target' => 'string -- The draft sent, else the saved translation.',
        'memory' => [[
            '#' => 'Similar stored pairs, best first, at least 30% alike and not already in the field.',
            'score' => 'number -- 1 for an exact source match.',
            'source' => 'string',
            'target' => 'string',
            'origin' => 'string',
            'content_id' => 'string?',
            'content_name' => 'string?',
            'field_path' => 'string?',
        ]],
        'locales' => [[
            'locale' => 'string',
            'text' => 'string',
        ]],
    ],
];
