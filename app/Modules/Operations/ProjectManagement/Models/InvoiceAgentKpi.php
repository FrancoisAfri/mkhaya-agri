<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One Yes/No question on the certificate's KPI block.
 *
 * Rows rather than columns because the six on the workbook are this funder's questions, and the
 * next funder asks different ones. The wording is stored, not a key: these are printed verbatim.
 */
class InvoiceAgentKpi extends Model
{
    use BelongsToCompany, HasPublicUuid, SoftDeletes;

    protected $table = 'pm_invoice_agent_kpis';

    protected $fillable = ['uuid', 'company_id', 'certificate_id', 'question', 'answer', 'position'];

    protected $casts = ['position' => 'integer'];

    public function certificate(): BelongsTo
    {
        return $this->belongsTo(InvoiceAgentCertificate::class, 'certificate_id');
    }
}
