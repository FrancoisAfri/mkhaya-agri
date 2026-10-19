<?php

namespace App\Modules\Agri\Models;

use App\Core\User\Models\User;
use App\Modules\Agri\Concerns\AuditsAgri;
use App\Modules\Agri\Concerns\HasPublicUuid;
use App\Modules\Agri\Services\FarmAccess;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person's access to one farm (spec §3.3 farm_memberships).
 *
 * Not confined by FarmAccessScope itself: FarmAccess reads this table to work out what everybody
 * else is confined to, and a scope on it would ask the question it is answering. Screens that list
 * memberships narrow them to farms the viewer can reach explicitly.
 */
class FarmMembership extends Model
{
    use AuditsAgri, BelongsToCompany, HasPublicUuid;

    public const ACTIVE = 'active';
    public const SUSPENDED = 'suspended';

    /** manager runs the farm; supervisor runs teams; worker does assigned work; finance sees money; viewer reads. */
    public const ROLES = ['manager', 'supervisor', 'worker', 'finance', 'viewer'];

    protected $table = 'farm_memberships';

    protected $fillable = [
        'uuid', 'company_id', 'farm_id', 'user_id', 'role', 'status', 'granted_by', 'suspended_at', 'suspended_by',
    ];

    protected $casts = ['suspended_at' => 'datetime'];

    protected static function booted(): void
    {
        // A changed membership must change what its holder can reach on the very next query.
        $flush = fn () => app(FarmAccess::class)->flush();
        static::saved($flush);
        static::deleted($flush);
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
