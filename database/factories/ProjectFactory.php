<?php

namespace Database\Factories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'repo_url' => 'https://github.com/'.fake()->userName().'/'.fake()->slug(),
            'local_path' => '/var/www/'.fake()->unique()->slug(),
            'default_branch' => 'main',
            'auto_deploy' => false,
        ];
    }
}
