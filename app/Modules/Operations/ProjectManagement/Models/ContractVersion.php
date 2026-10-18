<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The contract's wording as it read at one point in the negotiation.
 *
 * Written once and never updated. An amendment is a new row, not an edit of the last one — that is
 * what lets the register show who changed what, and what makes a signature against version 2
 * distinguishable from a signature against version 3.
 */
class ContractVersion extends Model
{
    use BelongsToCompany, HasPublicUuid;

    protected $table = 'pm_contract_versions';

    protected $fillable = [
        'uuid', 'company_id', 'contract_id', 'version_no', 'body',
        'change_note', 'content_sha256', 'created_by',
    ];

    protected $casts = [
        'version_no' => 'integer',
    ];

    /**
     * A contract with no tenant is a bug, never a record shared with everybody.
     *
     * Row level security matches `company_id = <session>`, and NULL never equals anything — so an
     * unstamped contract is invisible to every tenant user while still being visible to a platform
     * admin who has not picked a company. Two live contracts reached exactly that state: they
     * looked fine to the person who made them and did not exist for anyone else.
     */
    public static function requiresCompany(): bool
    {
        return true;
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(ProjectContract::class, 'contract_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(\App\Core\User\Models\User::class, 'created_by');
    }

    /** The hash of a body, computed one way in one place so signing and checking cannot diverge. */
    public static function hash(?string $body): ?string
    {
        return $body === null ? null : hash('sha256', $body);
    }
}
