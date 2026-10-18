<?php

namespace App\Modules\Operations\DocumentManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * A storage limit on a department, a person or a folder.
 *
 * One row per thing, enforced by a unique key: two limits on the same department is a contradiction
 * somebody set by accident, and whichever the code happened to read first would look arbitrary.
 */
class DocumentQuota extends Model
{
    use BelongsToCompany;

    protected $table = 'document_quotas';

    protected $guarded = ['id'];

    public const SCOPE_DEPARTMENT = 'department';
    public const SCOPE_USER = 'user';
    public const SCOPE_FOLDER = 'folder';

    public const SCOPES = [self::SCOPE_DEPARTMENT, self::SCOPE_USER, self::SCOPE_FOLDER];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
