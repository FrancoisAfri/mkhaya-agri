<?php

namespace App\Modules\Operations\WarehouseManagement\Models;

use App\Core\User\Models\User;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One person's count of one line in one round. Append-only: a recount is a new entry, never an edit. */
class CountEntry extends Model
{
    use BelongsToCompany;

    protected $table = 'wms_count_entries';

    public const UPDATED_AT = null;

    protected $fillable = ['company_id', 'count_line_id', 'round', 'user_id', 'qty', 'serials', 'client_ref', 'duplicate',
        'expected_at_entry', 'moved_since_snapshot', 'counted_at'];

    protected $casts = ['qty' => 'decimal:4', 'serials' => 'array', 'duplicate' => 'boolean', 'expected_at_entry' => 'decimal:4',
        'moved_since_snapshot' => 'decimal:4', 'counted_at' => 'datetime'];

    public function line(): BelongsTo { return $this->belongsTo(CountLine::class, 'count_line_id'); }

    public function user(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }
}
