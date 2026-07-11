<?php

namespace App\Enums;

enum ContentTranslationImportMode: string
{
    /** Store imported translations as a new draft version (nothing goes live). */
    case DRAFT = 'draft';

    /** Publish imported translations immediately. */
    case PUBLISH = 'publish';
}
