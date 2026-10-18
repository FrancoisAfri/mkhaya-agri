<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A notice to everyone on a project (EPIC-031).
 *
 * A null `project_id` addresses the whole portfolio. Severity drives how loudly it is delivered:
 * `urgent` is the only level that may go out by SMS, because SMS costs money per message and a
 * channel used for routine notices is one people stop reading.
 */
class Announcement extends Model
{
    use BelongsToCompany, HasPublicUuid, SoftDeletes;

    protected $table = 'pm_announcements';

    public const SEVERITIES = ['info', 'important', 'urgent'];

    protected $fillable = [
        'uuid', 'company_id', 'project_id', 'title', 'body', 'severity',
        'is_pinned', 'requires_ack', 'sent_sms', 'published_at', 'expires_at', 'created_by',
    ];

    protected $casts = [
        'is_pinned'    => 'boolean',
        'requires_ack' => 'boolean',
        'sent_sms'     => 'boolean',
        'published_at' => 'datetime',
        'expires_at'   => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function reads(): HasMany
    {
        return $this->hasMany(AnnouncementRead::class, 'announcement_id');
    }

    /**
     * Published, and not yet expired.
     *
     * Expiry is evaluated in the query rather than by a job that flips a flag: a notice about
     * Tuesday's road closure should stop being current on Wednesday whether or not anything ran.
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /** This project's notices plus the portfolio-wide ones, which apply to every project. */
    public function scopeForProject(Builder $query, ?int $projectId): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('project_id')
            ->when($projectId, fn (Builder $w, $id) => $w->orWhere('project_id', $id)));
    }
}
