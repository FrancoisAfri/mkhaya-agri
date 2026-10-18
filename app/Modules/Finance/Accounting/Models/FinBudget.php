<?php

namespace App\Modules\Finance\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use App\Modules\Finance\Accounting\Traits\BelongsToEntity;
use App\Modules\Tenancy\Traits\BelongsToCompany;

class FinBudget extends Model
{
    use BelongsToCompany;
    use BelongsToEntity;

    protected $table = 'fin_budgets';

    protected $fillable = [
        'entity_id', 'uuid', 'company_id', 'name', 'fiscal_year', 'period_type', 'status',
        'notes', 'approved_by', 'approved_at', 'created_by',
    ];

    protected $casts = ['fiscal_year' => 'integer', 'approved_at' => 'datetime'];

    protected $attributes = ['status' => 'draft', 'period_type' => 'monthly'];

    protected static function booted(): void
    {
        static::creating(fn (FinBudget $b) => $b->uuid ??= (string) Str::uuid());
    }

    public function lines(): HasMany
    {
        return $this->hasMany(FinBudgetLine::class, 'budget_id');
    }

    public function isEditable(): bool
    {
        return $this->status !== 'locked';
    }
}
