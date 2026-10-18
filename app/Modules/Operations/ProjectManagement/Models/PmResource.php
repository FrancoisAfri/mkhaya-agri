<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\AuditsChanges;
use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A resource Project Management owns: a room, a licence, a piece of plant nobody registered.
 *
 * Deliberately not forced into the Asset Register. A meeting room is not something the finance team
 * depreciates and a software seat is not a serial-numbered asset; inventing records for them there
 * would pollute a register other people rely on. Anything that *is* a registered asset or a fleet
 * vehicle is linked from those modules instead, and never duplicated here.
 */
class PmResource extends Model
{
    use BelongsToCompany, HasPublicUuid, SoftDeletes;

    protected $table = 'pm_resources';

    protected $fillable = [
        'uuid', 'company_id', 'kind', 'name', 'identifier', 'capacity', 'rate_per_day',
        'currency', 'available_from', 'available_to', 'is_active', 'notes',
    ];

    protected $casts = [
        'capacity'       => 'integer',
        'rate_per_day'   => 'decimal:2',
        'available_from' => 'date',
        'available_to'   => 'date',
        'is_active'      => 'boolean',
    ];

    public const KINDS = ['room', 'licence', 'plant', 'other'];

    public function scopeUsable(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('available_to')->orWhere('available_to', '>=', now()));
    }

    /** Every bookable resource, expired or not — the setup screen has to show the lapsed ones too. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** A licence past its date is not a resource, it is a renewal somebody has forgotten. */
    public function hasExpired(): bool
    {
        return $this->available_to !== null && $this->available_to->isPast();
    }

    /**
     * Within 60 days of running out.
     *
     * Kept as a question the record answers rather than a status column somebody has to remember to
     * change: a licence does not expire when a person updates a dropdown, it expires on its date.
     */
    public function expiringSoon(): bool
    {
        return $this->available_to !== null
            && ! $this->hasExpired()
            && $this->available_to->diffInDays(now()) <= 60;
    }
}
