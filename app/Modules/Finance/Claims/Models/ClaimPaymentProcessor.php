<?php

namespace App\Modules\Finance\Claims\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class ClaimPaymentProcessor extends Model
{
    protected $table = 'claim_payment_processors';

    protected $fillable = [
        'processor_type', 'role_name', 'user_id', 'is_active', 'notes', 'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function getProcessorUsers(): Collection
    {
        $processors = static::where('is_active', true)->get();
        $userIds    = collect();

        foreach ($processors as $proc) {
            if ($proc->processor_type === 'user' && $proc->user_id) {
                $userIds->push($proc->user_id);
            } elseif ($proc->processor_type === 'role' && $proc->role_name) {
                self::idsForRoles([$proc->role_name])->each(fn ($id) => $userIds->push($id));
            }
        }

        $unique = $userIds->unique()->values();

        // Fallback: if no processors are configured, notify finance managers and officers
        if ($unique->isEmpty()) {
            $fallbackRoles = ['finance manager', 'finance officer', 'super admin', 'administrator'];
            self::idsForRoles($fallbackRoles)->each(fn ($id) => $unique->push($id));
            $unique = $unique->unique()->values();
        }

        return $unique->isEmpty() ? collect() : User::whereIn('id', $unique)->get();
    }

    /**
     * The users holding any of these roles, ignoring names no installation has.
     *
     * `User::role()` throws RoleDoesNotExist when *any* name in the list is unknown, so this list —
     * written as a safety net for a book with no processors configured — took the approval down
     * with a 500 on every install that has no "finance officer" role. A role nobody has created is
     * a role nobody holds, which is an empty result, not an error.
     *
     * @param  list<string>  $roles
     * @return \Illuminate\Support\Collection<int, int>
     */
    private static function idsForRoles(array $roles): \Illuminate\Support\Collection
    {
        $existing = \Spatie\Permission\Models\Role::whereIn('name', $roles)
            ->where('guard_name', 'web')
            ->pluck('name')
            ->all();

        if ($existing === []) {
            return collect();
        }

        return User::role($existing)->pluck('id');
    }
}
