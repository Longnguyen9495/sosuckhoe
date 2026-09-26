<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            DrugSeeder::class,
            TemplateSeeder::class,
            ContentSeeder::class,
            DemoPatientBaDSeeder::class,
        ]);
    }
}
