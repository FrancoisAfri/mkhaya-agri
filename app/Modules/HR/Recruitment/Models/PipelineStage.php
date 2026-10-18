<?php

namespace App\Modules\HR\Recruitment\Models;

use App\Modules\HR\Recruitment\Enums\PipelineStageType;
use Database\Factories\Recruitment\PipelineStageFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PipelineStage extends Model
{
    use HasFactory;

    protected $table = 'recruitment_pipeline_stages';

    protected static function newFactory(): Factory
    {
        return PipelineStageFactory::new();
    }

    protected $fillable = [
        'job_id',
        'name',
        'type',
        'order_index',
        'is_active',
    ];

    protected $casts = [
        'type'      => PipelineStageType::class,
        'is_active' => 'boolean',
    ];

    // Relationships

    public function job()
    {
        return $this->belongsTo(Job::class);
    }

    public function applications()
    {
        return $this->hasMany(Application::class);
    }
}
