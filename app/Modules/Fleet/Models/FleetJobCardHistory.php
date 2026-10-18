<?php

namespace App\Modules\Fleet\Models;

use App\Core\User\Models\User;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** Append-only trail of everything that happened to a job card. */
class FleetJobCardHistory extends Model
{
    use BelongsToCompany;

    protected $table = 'fleet_job_card_history';

    protected $fillable = [
        'uuid', 'company_id', 'job_card_id', 'action', 'from_status', 'to_status',
        'description', 'performed_by', 'performed_at',
    ];

    protected $casts = ['performed_at' => 'datetime'];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function ($m) {
            $m->uuid         ??= (string) Str::uuid();
            $m->performed_at ??= now();
        });
    }

    public function jobCard(): BelongsTo { return $this->belongsTo(FleetJobCard::class, 'job_card_id'); }
    public function performer(): BelongsTo { return $this->belongsTo(User::class, 'performed_by'); }
}
