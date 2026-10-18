<?php

namespace App\Modules\HR\Recruitment\Models;

use App\Modules\HR\Recruitment\Enums\SocialProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use App\Shared\Traits\KeepsDatabaseDefaults;

class SocialAccount extends Model
{
    use SoftDeletes, KeepsDatabaseDefaults;

    protected $table = 'recruitment_social_accounts';

    protected $fillable = [
        'provider',
        'display_name',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'meta',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'provider'         => SocialProvider::class,
        'token_expires_at' => 'datetime',
        'meta'             => 'array',
        'is_active'        => 'boolean',
        'access_token'     => 'encrypted',
        'refresh_token'    => 'encrypted',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $m): void {
            $m->uuid ??= (string) Str::uuid();
        });
    }

    public function posts(): HasMany
    {
        return $this->hasMany(SocialPost::class, 'social_account_id');
    }
}
