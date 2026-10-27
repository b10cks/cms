<?php

namespace App\Enums;

enum AiKnowledgeType: string
{
    /** Always in the prompt for its scope. */
    case Style = 'style';

    /** Chunked on publish and retrieved on demand with `search_knowledge`. */
    case Reference = 'reference';
}
