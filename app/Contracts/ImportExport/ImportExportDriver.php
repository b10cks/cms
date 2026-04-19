<?php

namespace App\Contracts\ImportExport;

interface ImportExportDriver
{
    public function getFormat(): string;
}
