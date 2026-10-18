<?php

namespace App\Modules\Communication\EmailCampaigns\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class EmailCampaignTemplate extends Model
{
    use SoftDeletes;

    protected $table = 'communication_email_campaign_templates';

    protected $fillable = [
        'uuid',
        'title',
        'subject',
        'from_name',
        'from_email',
        'body',
        'created_by',
    ];

    protected static function booted(): void
    {
        static::creating(fn ($m) => $m->uuid ??= (string) Str::uuid());
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
