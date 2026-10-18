<?php

namespace App\Modules\Finance\Sars\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A generated SARS return kept for the audit trail: VAT201, EMP201, EMP501, IRP5/IT3(a) or
 * UI-19. Carries a status lifecycle (draft → generated → validated → submitted → accepted /
 * rejected), a version (amendments create a new version referencing the previous), a JSON
 * snapshot of the figures, and the validation result captured at generation time.
 */
class SarsSubmission extends Model
{
    use BelongsToCompany;

    protected $table = 'sars_submissions';

    protected $fillable = [
        'uuid', 'company_id', 'bureau_company_id', 'subject_label', 'reference', 'tax_type', 'period',
        'status', 'version', 'amends_id', 'totals', 'payload', 'validation', 'file_path', 'notes',
        'generated_by', 'generated_at', 'submitted_at',
    ];

    protected $casts = [
        'totals'       => 'array',
        'payload'      => 'array',
        'validation'   => 'array',
        'generated_at' => 'datetime',
        'submitted_at' => 'datetime',
    ];

    public const TAX_TYPES = ['vat201', 'emp201', 'emp501', 'irp5', 'it3a', 'ui19'];
    public const STATUSES = ['draft', 'generated', 'validated', 'submitted', 'accepted', 'rejected'];

    protected static function booted(): void
    {
        static::creating(function (SarsSubmission $s) {
            $s->uuid ??= (string) Str::uuid();
            $s->reference ??= 'SUB-' . str_pad((string) (static::withoutGlobalScopes()->max('id') + 1), 6, '0', STR_PAD_LEFT);
        });
    }

    public function amends(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(self::class, 'amends_id');
    }

    /** Who moved this return, from what, to what, and when. Append-only. */
    public function events(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SarsSubmissionEvent::class, 'submission_id');
    }

    /** The tenant's own returns (not filed on behalf of a payroll-bureau client). */
    public function scopeOwnReturns($query)
    {
        return $query->whereNull('bureau_company_id');
    }

    /** Returns filed on behalf of a payroll-bureau client. */
    public function scopeBureauFilings($query)
    {
        return $query->whereNotNull('bureau_company_id');
    }
}
