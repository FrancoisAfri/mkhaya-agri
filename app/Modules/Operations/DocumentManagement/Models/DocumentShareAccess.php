<?php

namespace App\Modules\Operations\DocumentManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One opening of one shared link.
 *
 * A row per access rather than a counter, because the question afterwards is "when did this leave
 * and where did it go", and a number answers neither. No updated_at: an access happened once and is
 * never amended.
 */
class DocumentShareAccess extends Model
{
    use BelongsToCompany;

    protected $table = 'document_share_accesses';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $casts = ['created_at' => 'datetime'];

    public const VIEWED = 'viewed';
    public const DOWNLOADED = 'downloaded';

    public function share(): BelongsTo
    {
        return $this->belongsTo(DocumentShare::class, 'share_id');
    }
}
