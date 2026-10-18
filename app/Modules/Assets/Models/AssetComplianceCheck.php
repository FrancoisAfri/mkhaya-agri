<?php

namespace App\Modules\Assets\Models;

use App\Modules\Assets\Enums\ComplianceCheckResult;
use App\Shared\Traits\Uuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetComplianceCheck extends Model
{
    use Uuids;

    protected $table = 'asset_compliance_checks';

    protected $fillable = [
        'uuid', 'rule_id', 'asset_id', 'run_id', 'check_result',
        'last_compliant_at', 'next_due_at', 'days_overdue', 'notes',
    ];

    protected $casts = [
        'last_compliant_at' => 'datetime',
        'next_due_at'       => 'datetime',
        'check_result'      => ComplianceCheckResult::class,
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AssetComplianceRule::class, 'rule_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Assets::class, 'asset_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AssetComplianceRun::class, 'run_id');
    }
}
