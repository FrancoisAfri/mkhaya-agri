<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Operations\Manufacturing\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** Who did what to a revision, and why -- the revision history the spec asks for. */
class BomApproval extends Model
{
    use BelongsToCompany, HasPublicUuid;

    protected $table = 'mfg_bom_approvals';

    protected $fillable = [
        'uuid', 'company_id', 'bom_id', 'action', 'actor_id', 'acted_at', 'comment',
    ];

    protected $casts = ['acted_at' => 'datetime'];

    public const SUBMITTED = 'SUBMITTED';
    public const APPROVED = 'APPROVED';
    public const REJECTED = 'REJECTED';
    public const SUPERSEDED = 'SUPERSEDED';
    public const OBSOLETED = 'OBSOLETED';
    public const REVISED = 'REVISED';

    public function bom(): BelongsTo
    {
        return $this->belongsTo(Bom::class, 'bom_id');
    }
}
