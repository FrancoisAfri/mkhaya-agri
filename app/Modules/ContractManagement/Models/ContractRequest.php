<?php

namespace App\Modules\ContractManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ContractRequest extends Model
{
    use BelongsToCompany, LogsActivity, SoftDeletes;

    protected $table = 'contract_requests';

    protected $fillable = [
        'uuid',
        'request_number',
        'request_title',
        'requested_by',
        'contract_type_id',
        'contract_length',
        'contract_length_unit',
        'criticality',
        'contract_value',
        'summary',
        'motivation',
        'status',
        'recommendation',
        'rejection_reason',
    ];

    protected $casts = [
        'criticality' => 'integer',
        'contract_value' => 'decimal:2',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Contract Management')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected static function booted(): void
    {
        static::creating(function (ContractRequest $model) {
            $model->uuid ??= (string) Str::uuid();
            if (empty($model->request_number)) {
                $max = static::withTrashed()->max('id') ?? 0;
                $model->request_number = 'CR'.date('Y').Str::padLeft((string) ($max + 1), 5, '0');
            }
        });
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(ContractType::class, 'contract_type_id');
    }

    public function scopeFilter(Builder $query, array $filters): Builder
    {
        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('request_title', 'ilike', "%{$search}%")
                    ->orWhere('request_number', 'ilike', "%{$search}%");
            });
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query;
    }
}
