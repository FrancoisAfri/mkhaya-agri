<?php

namespace App\Modules\Operations\Manufacturing\Models;

use App\Modules\Operations\Manufacturing\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One message in or out of an integration, processed at most once. */
class IntegrationEvent extends Model
{
    use BelongsToCompany, HasPublicUuid;

    public const MAX_ATTEMPTS = 5;

    protected $table = 'mfg_integration_events';

    protected $fillable = ['uuid', 'company_id', 'integration_id', 'direction', 'event_type', 'external_id', 'idempotency_key', 'payload', 'status',
        'attempts', 'next_attempt_at', 'last_error', 'duplicates', 'result_type', 'result_id', 'result_reference', 'processed_at'];

    protected $casts = ['payload' => 'array', 'next_attempt_at' => 'datetime', 'processed_at' => 'datetime', 'attempts' => 'integer', 'duplicates' => 'integer'];

    public function integration(): BelongsTo { return $this->belongsTo(Integration::class, 'integration_id'); }
}
