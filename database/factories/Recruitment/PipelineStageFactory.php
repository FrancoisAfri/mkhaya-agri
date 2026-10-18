<?php

namespace Database\Factories\Recruitment;

use App\Modules\HR\Recruitment\Enums\PipelineStageType;
use App\Modules\HR\Recruitment\Models\Job;
use App\Modules\HR\Recruitment\Models\PipelineStage;
use Illuminate\Database\Eloquent\Factories\Factory;

class PipelineStageFactory extends Factory
{
    protected $model = PipelineStage::class;

    public function definition(): array
    {
        return [
            'job_id'      => Job::factory(),
            'name'        => fake()->randomElement(['Phone Screen', 'Technical Test', 'Final Interview']),
            'type'        => PipelineStageType::Screening->value,
            'order_index' => fake()->numberBetween(1, 10),
            'is_active'   => true,
        ];
    }
}
