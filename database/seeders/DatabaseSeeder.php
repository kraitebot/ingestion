<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Kraite\Core\Database\Seeders\KraiteSeeder;

final class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(KraiteSeeder::class);
    }
}
