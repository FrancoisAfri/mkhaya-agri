<?php

namespace App\Modules\SupplierManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class SupplierBbbee extends Model
{
    use BelongsToCompany, LogsActivity;

    protected $table = 'supplier_bbbee';

    protected $fillable = [
        'supplier_id', 'level', 'black_ownership_pct', 'black_women_ownership_pct',
        'recognition_pct', 'certificate_expiry', 'verified_by', 'verified_at', 'notes',
    ];

    protected $casts = [
        'level'                     => 'integer',
        'black_ownership_pct'       => 'decimal:2',
        'black_women_ownership_pct' => 'decimal:2',
        'recognition_pct'          => 'decimal:2',
        'certificate_expiry'       => 'date',
        'verified_at'              => 'datetime',
    ];

    /**
     * SA generic scorecard: B-BBEE level => procurement recognition %.
     */
    public const RECOGNITION_MAP = [
        1 => 135, 2 => 125, 3 => 110, 4 => 100,
        5 => 80,  6 => 60,  7 => 50,  8 => 10,
    ];

    public static function recognitionForLevel(?int $level): float
    {
        return (float) (self::RECOGNITION_MAP[$level] ?? 0);
    }

    protected static function booted(): void
    {
        static::saving(function (SupplierBbbee $bbbee) {
            // Auto-derive recognition % from level when not explicitly provided.
            if ($bbbee->recognition_pct === null) {
                $bbbee->recognition_pct = self::recognitionForLevel($bbbee->level);
            }
        });
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Supplier Management')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
