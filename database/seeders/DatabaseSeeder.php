<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seeds the company watchlist and job sources only. No job listings, matches or applicant
     * data are ever seeded: all of that comes from real sources.
     */
    public function run(): void
    {
        $this->call(CompanyWatchlistSeeder::class);
    }
}
