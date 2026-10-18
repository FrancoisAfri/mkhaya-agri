<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * That a particular person saw — and, where asked, acknowledged — a notice.
 *
 * Read and acknowledged are separate columns because they answer different questions. "It appeared
 * on their screen" is not "they accepted the new site rule", and a contractual or safety notice
 * needs the second one, given deliberately.
 */
class AnnouncementRead extends Model
{
    use BelongsToCompany, HasPublicUuid;

    protected $table = 'pm_announcement_reads';

    protected $fillable = [
        'uuid', 'company_id', 'announcement_id', 'user_id', 'read_at', 'acknowledged_at',
    ];

    protected $casts = [
        'read_at'         => 'datetime',
        'acknowledged_at' => 'datetime',
    ];

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class, 'announcement_id');
    }
}
