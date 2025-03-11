<?php

namespace App\Enums;

enum ConnectionDriver: string
{
    case MYSQL = 'mysql';
    case PGSQL = 'pgsql';
    case MONGODB = 'mongodb';
    case SQLITE = 'sqlite';

    public function defaultPort(): int
    {
        return match ($this) {
            self::MYSQL => 3306,
            self::PGSQL => 5432,
            self::MONGODB => 27017,
            self::SQLITE => 0,
        };
    }
}
