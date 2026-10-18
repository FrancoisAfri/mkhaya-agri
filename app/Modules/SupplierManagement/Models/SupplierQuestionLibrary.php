<?php

namespace App\Modules\SupplierManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class SupplierQuestionLibrary extends Model
{
    use BelongsToCompany, LogsActivity;

    protected $table = 'supplier_question_libraries';

    protected $fillable = [
        'uuid', 'template_id', 'question', 'description', 'weight_percentage', 'minimum_threshold', 'sort_order',
    ];

    protected $casts = [
        'weight_percentage' => 'decimal:2',
        'minimum_threshold' => 'integer',
        'sort_order' => 'integer',
    ];

    /** Addressed by uuid, like the template it belongs to, so one route reads consistently. */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function booted(): void
    {
        static::creating(function (SupplierQuestionLibrary $model) {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(SupplierPerformanceTemplate::class, 'template_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Supplier Management')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
