<?php

namespace App\Modules\Finance\Tax\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One version of the rule for a tax code (§5.2).
 *
 * Not entity-scoped by global scope: a rule with no entity applies to every business of the tenant,
 * and the Finance entity scope would hide exactly those. Callers filter by entity explicitly.
 */
class TaxRuleVersion extends Model
{
    use BelongsToCompany;

    public const TREATMENTS = ['standard', 'zero_rated', 'exempt', 'out_of_scope', 'reduced', 'other'];

    public const DIRECTIONS = ['output', 'input', 'both', 'none'];

    public const ROUNDING = ['half_up', 'half_even', 'down', 'up'];

    public static function requiresCompany(): bool
    {
        return true;
    }

    protected $table = 'tax_rule_versions';

    protected $guarded = ['id', 'uuid', 'company_id'];

    protected $casts = [
        'effective_from' => 'date', 'effective_to' => 'date', 'rate' => 'string',
        'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'published_at' => 'datetime',
        'retired_at' => 'datetime', 'simulation' => 'array', 'precision' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $r) => $r->uuid ??= (string) Str::uuid());

        // TAX-RULE-003: a published rule is the basis of past calculations. Only its retirement
        // (closing effective_to, status → retired) may change it; anything else is a new version.
        static::updating(function (self $r) {
            if ($r->getOriginal('status') !== 'published') {
                return;
            }
            $allowed = ['status', 'effective_to', 'retired_at', 'updated_at'];
            $changed = array_diff(array_keys($r->getDirty()), $allowed);
            if ($changed !== []) {
                throw new \App\Modules\Finance\Tax\Exceptions\TaxRuleException(
                    'A published tax rule cannot be edited. Create a new version instead (changed: ' . implode(', ', $changed) . ').'
                );
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function taxType(): BelongsTo
    {
        return $this->belongsTo(TaxType::class);
    }

    public function appliesOn(\DateTimeInterface|string $date): bool
    {
        $d = \Illuminate\Support\Carbon::parse($date)->startOfDay();

        return $d->gte($this->effective_from) && ($this->effective_to === null || $d->lte($this->effective_to));
    }
}
