<?php

namespace App\Modules\HR\Recruitment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SocialPost extends Model
{
    protected $table = 'recruitment_social_posts';

    protected $fillable = [
        'vacancy_id',
        'social_account_id',
        'status',
        'external_post_id',
        'external_url',
        'error_message',
        'posted_at',
    ];

    protected $casts = [
        'posted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $m): void {
            $m->uuid ??= (string) Str::uuid();
        });
    }

    public function vacancy(): BelongsTo
    {
        return $this->belongsTo(Vacancy::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'social_account_id');
    }
}
