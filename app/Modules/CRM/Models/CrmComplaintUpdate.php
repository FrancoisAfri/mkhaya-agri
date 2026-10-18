<?php

namespace App\Modules\CRM\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmComplaintUpdate extends Model
{
    protected $table = 'crm_complaint_updates';

    protected $fillable = ['complaint_id', 'type', 'from_status', 'to_status', 'note', 'user_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
