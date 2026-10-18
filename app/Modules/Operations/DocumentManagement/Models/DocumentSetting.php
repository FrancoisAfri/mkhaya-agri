<?php

namespace App\Modules\Operations\DocumentManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** What this tenant allows to be uploaded, and how big. */
class DocumentSetting extends Model
{
    use BelongsToCompany;

    protected $table = 'document_settings';

    protected $guarded = ['id'];

    protected $casts = [
        'allowed_extensions' => 'array',
        'blocked_extensions' => 'array',
        'auto_name' => 'boolean',
        'require_description' => 'boolean',
        'max_file_size_mb' => 'integer',
        'max_files_per_upload' => 'integer',
        'expiry_reminder_days' => 'array',
        'expiry_notify_roles' => 'array',
        'expiry_reminders_enabled' => 'boolean',
        'sharing_enabled' => 'boolean',
        'share_max_days' => 'integer',
        'ai_enabled' => 'boolean',
        'ai_include_confidential' => 'boolean',
        'auto_delete_enabled' => 'boolean',
        'disposal_grace_days' => 'integer',
        'watermark_shares_by_default' => 'boolean',
        'recycle_bin_days' => 'integer',
        'recycle_bin_auto_purge' => 'boolean',
    ];

    /** The tenant's settings, or the defaults, without making every caller handle the null. */
    public static function current(): self
    {
        return static::query()->first() ?? new self([
            'max_file_size_mb' => 25,
            'max_files_per_upload' => 20,
            // The six thresholds from the requirement. A company that wants different ones changes
            // them; a company that never opens the screen still gets warned.
            'expiry_reminder_days' => \App\Modules\Operations\DocumentManagement\Services\ExpiryService::DEFAULT_THRESHOLDS,
            'expiry_reminders_enabled' => true,
            // Sharing stays off until somebody turns it on. A repository does not acquire the
            // ability to publish its contents to the internet on the day it is upgraded.
            'sharing_enabled' => false,
            'share_max_days' => 30,
            // Sending a document to a model discloses it. Off until an organisation decides
            // otherwise, and confidential documents need the second switch as well.
            'ai_enabled' => false,
            'ai_include_confidential' => false,
            // Automatic deletion is off for the company as well as per category: turning it on is a
            // decision somebody makes once, deliberately, rather than a default they inherit.
            'auto_delete_enabled' => false,
            'disposal_grace_days' => 7,
            'recycle_bin_days' => 30,
            // Permanent destruction on a timer is the same argument as automatic disposal, and the
            // answer is the same: somebody should be able to say who did it.
            'recycle_bin_auto_purge' => false,
        ]);
    }
}
