<?php

namespace App\Enums;

/**
 * "standard" provisions one database per space with dedicated credentials
 * (requires admin DB privileges); "shared" fits shared webhosts without
 * CREATE DATABASE rights — spaces live in the main database behind a table
 * prefix, or in per-space sqlite files.
 */
enum InstallProfile: string
{
    case STANDARD = 'standard';
    case SHARED = 'shared';
}
