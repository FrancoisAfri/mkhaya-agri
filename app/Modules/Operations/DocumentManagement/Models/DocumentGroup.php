<?php

namespace App\Modules\Operations\DocumentManagement\Models;

use App\Core\User\Models\User;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * A set of people assembled for a purpose.
 *
 * Access given to a group is one row that stays right as people come and go. Access given to four
 * named people is four rows nobody remembers, and the fifth auditor gets added by hand slightly
 * differently.
 */
class DocumentGroup extends Model
{
    use BelongsToCompany;

    protected $table = 'document_groups';

    protected $guarded = ['id'];

    protected $casts = ['expires_at' => 'datetime'];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'document_group_members', 'group_id', 'user_id')
            ->withPivot(['expires_at', 'added_by', 'created_at']);
    }

    /** Whether the group itself has lapsed — the engagement ended. */
    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * The people it currently covers.
     *
     * A membership can lapse on its own — a secondee, somebody covering leave — without the group
     * being temporary, so both dates are checked.
     */
    public function currentMembers()
    {
        return $this->members()
            ->wherePivot('expires_at', null)
            ->orWherePivot('expires_at', '>', Carbon::now());
    }

    /** @param \Illuminate\Database\Eloquent\Builder<self> $query */
    public function scopeLive($query)
    {
        return $query->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', Carbon::now()));
    }
}
