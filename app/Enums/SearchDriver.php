<?php

namespace App\Enums;

enum SearchDriver: string
{
    case MYSQL = 'mysql';
    case OPENSEARCH = 'opensearch';

    public function isOpenSearch(): bool
    {
        return $this === self::OPENSEARCH;
    }

    public function isMySql(): bool
    {
        return $this === self::MYSQL;
    }
}
