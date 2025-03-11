<?php

namespace App\Enums;

enum BlockType: string
{
    case TEXT = 'text';
    case TEXTAREA = 'textarea';
    case RICH_TEXT = 'rich_text';
    case MARKDOWN = 'markdown';
    case NUMBER = 'number';
    case DATETIME = 'datetime';
    case BOOLEAN = 'boolean';
    case OPTION = 'option';
    case OPTIONS = 'options';
    case ASSET = 'asset';
    case ASSETS = 'assets';
    case CUSTOM = 'custom';
    case BLOCKS = 'blocks';

}
