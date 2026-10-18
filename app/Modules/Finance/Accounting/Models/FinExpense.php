<?php

namespace App\Modules\Finance\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use App\Modules\Finance\Accounting\Traits\BelongsToEntity;
use App\Modules\Tenancy\Traits\BelongsToCompany;

class FinExpense extends Model
{
    use BelongsToCompany;
    use BelongsToEntity;

    protected $table = 'fin_expenses';

    protected $fillable = [
        'entity_id', 'uuid', 'company_id', 'employee_id', 'category', 'description', 'expense_account_id',
        'amount', 'tax_amount', 'total', 'payment_method', 'contra_code', 'status', 'expense_date',
        'is_mileage', 'km', 'rate', 'journal_id', 'approved_by', 'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2', 'tax_amount' => 'decimal:2', 'total' => 'decimal:2',
        'km' => 'decimal:2', 'rate' => 'decimal:4', 'is_mileage' => 'boolean', 'expense_date' => 'date',
    ];

    protected $attributes = ['status' => 'draft', 'payment_method' => 'reimbursable', 'is_mileage' => false];

    public const METHODS = ['reimbursable' => 'Reimburse employee', 'petty_cash' => 'Petty cash', 'company_card' => 'Company card / bank'];

    protected static function booted(): void
    {
        static::creating(fn (FinExpense $e) => $e->uuid ??= (string) Str::uuid());
    }
}
