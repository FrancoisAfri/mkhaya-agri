<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** What one manufacturing event does to the books, approved by finance (MFG-021). */
class AccountingRule extends Model
{
    use \App\Modules\Operations\Manufacturing\Concerns\AuditsManufacturing;
    use BelongsToCompany;

    public const POST = 'POST';
    public const MEMO = 'MEMO';
    public const NONE = 'NONE';

    protected $table = 'mfg_accounting_rules';

    protected $fillable = ['company_id', 'event_type', 'treatment', 'debit_account_code', 'credit_account_code', 'description', 'status', 'changed_by',
        'approved_by', 'approved_at', 'notes'];

    protected $casts = ['approved_at' => 'datetime'];
}
