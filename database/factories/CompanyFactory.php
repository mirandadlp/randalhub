<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/** Test fixtures only — never used to seed real data. @extends Factory<Company> */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        return ['name' => $this->faker->unique()->company(), 'industry' => 'SaaS', 'is_watched' => true, 'discovered_via' => 'user'];
    }
}
