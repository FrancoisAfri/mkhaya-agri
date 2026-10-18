<?php

namespace App\Modules\CRM\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class CrmMailbox extends Model
{
    protected $table = 'crm_mailboxes';

    protected $fillable = [
        'name', 'purpose', 'email', 'host', 'port', 'encryption', 'username', 'password', 'folder',
        'active', 'ai_filter', 'notify_roles', 'default_owner_id', 'last_uid', 'last_checked_at',
        'last_status', 'created_by',
    ];

    protected $casts = [
        'active'          => 'boolean',
        'ai_filter'       => 'boolean',
        'notify_roles'    => 'array',
        'last_checked_at' => 'datetime',
        'port'            => 'integer',
        'last_uid'        => 'integer',
    ];

    protected $hidden = ['password'];

    public function defaultOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'default_owner_id');
    }

    /** Password is stored encrypted. */
    public function setPasswordAttribute($value): void
    {
        // Avoid double-encrypting an already-encrypted value on resave.
        $this->attributes['password'] = $value === null || $value === '' ? $value : Crypt::encryptString($value);
    }

    public function plainPassword(): ?string
    {
        if (empty($this->attributes['password'])) return null;
        try {
            return Crypt::decryptString($this->attributes['password']);
        } catch (\Throwable) {
            return null;
        }
    }
}
