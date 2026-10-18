<?php

namespace App\Modules\SupplierManagement\Models;

use App\Modules\SupplierManagement\DueDiligence\Support\RiskModel;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One risk assessment, as it stood when it was made.
 *
 * Snapshots rather than a column on the supplier, for the same reason verifications append: a score
 * with no history can state today's position but cannot say a supplier got worse, and "got worse" is
 * the entire product. The weights are stored alongside the components so an assessment stays
 * explicable after somebody retunes the model — otherwise last quarter's 63 can only be asserted.
 */
class SupplierRiskScore extends Model
{
    use BelongsToCompany;

    protected $table = 'supplier_risk_scores';

    protected $fillable = [
        'uuid', 'supplier_id', 'total', 'band', 'tier', 'components', 'weights',
        'auto_approvable', 'blockers', 'edd_triggers', 'previous_total',
        'trigger', 'calculated_by', 'calculated_at',
    ];

    protected $casts = [
        'total' => 'decimal:2',
        'previous_total' => 'decimal:2',
        'components' => 'array',
        'weights' => 'array',
        'blockers' => 'array',
        'edd_triggers' => 'array',
        'auto_approvable' => 'boolean',
        'tier' => 'integer',
        'calculated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $score) {
            $score->uuid ??= (string) Str::uuid();
            $score->calculated_at ??= Carbon::now();
        });
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public static function latestFor(int $supplierId): ?self
    {
        return static::query()
            ->where('supplier_id', $supplierId)
            ->orderByDesc('calculated_at')->orderByDesc('id')
            ->first();
    }

    /** How far the score moved since the assessment before it. Positive means worse. */
    public function movement(): ?float
    {
        return $this->previous_total === null ? null : round((float) $this->total - (float) $this->previous_total, 2);
    }

    public function bandLabel(): string
    {
        return RiskModel::bands()[$this->band]['label'] ?? ucfirst((string) $this->band);
    }

    /** What this band demands procedurally — standard, additional checks, EDD, or an investigation. */
    public function requires(): string
    {
        return RiskModel::bands()[$this->band]['requires'] ?? 'standard';
    }
}
