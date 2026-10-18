<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** One step in a loss investigation. Append-only: the database refuses edits and deletes. */
class LossEvent extends Model
{
    use BelongsToCompany;

    protected $table = 'mfg_loss_events';

    protected $fillable = ['company_id', 'finding_id', 'action', 'from_status', 'to_status', 'actor_id', 'notes', 'detail', 'acted_at'];

    protected $casts = ['detail' => 'array', 'acted_at' => 'datetime'];
}
