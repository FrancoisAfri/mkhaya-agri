<?php

namespace App\Modules\Tenancy\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A person's place in an organisation they may switch to (see the migration for the why). */
class CompanyMembership extends Model
{
    public const ACTIVE = 'active';
    public const SUSPENDED = 'suspended';

    protected $table = 'company_memberships';

    protected $guarded = ['id'];

    protected $casts = ['roles' => 'array', 'permissions' => 'array', 'last_used_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withoutGlobalScopes();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
