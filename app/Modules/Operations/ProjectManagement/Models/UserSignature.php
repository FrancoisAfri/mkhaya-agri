<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * A signature somebody drew once and can use again.
 *
 * Redrawing a signature with a mouse on every document is how people end up with five signatures
 * that look like five different people. Tender Pursuit solved this already; this is the same shape
 * so the two stores can be merged later without either changing meaning.
 */
class UserSignature extends Model
{
    use BelongsToCompany, HasPublicUuid;

    protected $table = 'pm_user_signatures';

    /** A full signature, or just initials for a page corner. */
    public const KINDS = ['signature', 'initial'];

    protected $fillable = ['uuid', 'company_id', 'user_id', 'name', 'kind', 'image'];

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id'    => $this->uuid,
            'name'  => $this->name,
            'kind'  => $this->kind,
            'image' => $this->image,
        ];
    }
}
