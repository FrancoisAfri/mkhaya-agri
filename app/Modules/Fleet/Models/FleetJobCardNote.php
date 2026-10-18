<?php

namespace App\Modules\Fleet\Models;

use App\Core\User\Models\User;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/** A note on a job card. note_type separates general notes from cancellation and mechanic notes. */
class FleetJobCardNote extends Model
{
    use SoftDeletes, BelongsToCompany;

    protected $table = 'fleet_job_card_notes';

    protected $fillable = ['uuid', 'company_id', 'job_card_id', 'note_type', 'body', 'created_by'];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn ($m) => $m->uuid ??= (string) Str::uuid());
    }

    public function jobCard(): BelongsTo { return $this->belongsTo(FleetJobCard::class, 'job_card_id'); }
    public function author(): BelongsTo  { return $this->belongsTo(User::class, 'created_by'); }
}
