<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class StaticDataSeeder extends Seeder
{
    public function run()
    {
        \DB::statement(file_get_contents(base_path('database/seeders/countries.sql')));
    }
}
