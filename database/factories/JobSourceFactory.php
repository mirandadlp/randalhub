<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\JobSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/** Test fixtures only. @extends Factory<JobSource> */
class JobSourceFactory extends Factory
{
    protected $model = JobSource::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'type' => 'greenhouse',
            'identifier' => $this->faker->unique()->slug(2),
            'enabled' => true,
        ];
    }
}
