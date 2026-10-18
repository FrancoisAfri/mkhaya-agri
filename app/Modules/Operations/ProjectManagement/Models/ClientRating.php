<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a client actually said, in the month they said it (§1).
 *
 * The dashboard's satisfaction score is the average of these and nothing else. Deriving a
 * "satisfaction" figure from delivery metrics would be inventing a client's opinion out of data
 * they never saw — and the first time it disagreed with a real complaint, the whole dashboard would
 * stop being trusted.
 */
class ClientRating extends Model
{
    use BelongsToCompany, HasPublicUuid;

    protected $table = 'pm_client_ratings';

    protected $fillable = ['uuid', 'company_id', 'project_id', 'user_id', 'score', 'comment', 'period_month'];

    protected $casts = ['score' => 'integer', 'period_month' => 'date'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
