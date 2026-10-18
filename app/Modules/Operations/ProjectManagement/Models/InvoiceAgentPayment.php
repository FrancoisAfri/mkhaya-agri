<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Shared\Traits\KeepsDatabaseDefaults;

/**
 * One line of "Summary of actual payment made" at the foot of the certificate.
 *
 * A record of what the funder actually paid, which is not the same list as the invoices we issued:
 * it carries payments to the contractor and cession payments that never appeared on ours. The
 * final row is this invoice's own claim and is kept in step with it — see `is_current_claim`.
 */
class InvoiceAgentPayment extends Model
{
    use BelongsToCompany, HasPublicUuid, SoftDeletes, KeepsDatabaseDefaults;

    protected $table = 'pm_invoice_agent_payments';

    protected $fillable = [
        'uuid', 'company_id', 'certificate_id', 'position', 'paid_on', 'service_provider',
        'to_consultant', 'to_contractor', 'cession', 'is_current_claim',
    ];

    protected $casts = [
        'position'         => 'integer',
        'paid_on'          => 'date',
        'to_consultant'    => 'decimal:2',
        'to_contractor'    => 'decimal:2',
        'cession'          => 'decimal:2',
        'is_current_claim' => 'boolean',
    ];

    public function certificate(): BelongsTo
    {
        return $this->belongsTo(InvoiceAgentCertificate::class, 'certificate_id');
    }
}
