<?php

namespace App\Modules\Operations\DocumentManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One grant on a folder: who, and what they may do.
 *
 * Permissions sit on the folder because that is how people describe them — "the HR team can see
 * Employee Files" is a single statement about a place, not a statement repeated for every payslip
 * filed there. They inherit down the tree, so a subfolder is covered by its parent until it says
 * otherwise.
 *
 * The subject is a role, a user or a department. Roles cover almost everything; naming one person
 * is for the exception that always turns up, and naming a department follows the org structure the
 * platform already has rather than duplicating it.
 */
class DocumentFolderPermission extends Model
{
    use BelongsToCompany;

    protected $table = 'document_folder_permissions';

    protected $guarded = ['id'];

    protected $casts = [
        'expires_at' => 'datetime',
        'can_view' => 'boolean',
        'can_download' => 'boolean',
        'can_manage' => 'boolean',
        'can_upload' => 'boolean',
        'can_edit' => 'boolean',
        'can_delete' => 'boolean',
    ];

    public const SUBJECT_ROLE = 'role';
    public const SUBJECT_USER = 'user';
    public const SUBJECT_DEPARTMENT = 'department';
    public const SUBJECT_GROUP = 'group';

    public const SUBJECTS = [self::SUBJECT_ROLE, self::SUBJECT_USER, self::SUBJECT_DEPARTMENT, self::SUBJECT_GROUP];

    protected static function booted(): void
    {
        static::creating(fn (self $p) => $p->uuid ??= (string) Str::uuid());
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(DocumentFolder::class, 'folder_id');
    }

    /** The abilities this grant confers, for merging with others that also apply. */
    public function abilities(): array
    {
        return [
            'view' => (bool) $this->can_view,
            'download' => (bool) $this->can_download,
            'manage' => (bool) $this->can_manage,
            'upload' => (bool) $this->can_upload,
            'edit' => (bool) $this->can_edit,
            'delete' => (bool) $this->can_delete,
        ];
    }
}
