<?php

namespace App\Modules\Operations\DocumentManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One extra thing a kind of document carries.
 *
 * The key and the label are separate on purpose: a label renamed must not orphan every value
 * already recorded under the old wording, and people rename labels far more often than they mean
 * to change what a field is.
 */
class DocumentCategoryField extends Model
{
    use BelongsToCompany;

    protected $table = 'document_category_fields';

    protected $guarded = ['id'];

    protected $casts = [
        'options' => 'array',
        'is_required' => 'boolean',
    ];

    public const TYPE_TEXT = 'text';
    public const TYPE_NUMBER = 'number';
    public const TYPE_DATE = 'date';
    public const TYPE_BOOLEAN = 'boolean';
    public const TYPE_SELECT = 'select';

    public const TYPES = [
        self::TYPE_TEXT, self::TYPE_NUMBER, self::TYPE_DATE,
        self::TYPE_BOOLEAN, self::TYPE_SELECT,
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(DocumentCategory::class, 'category_id');
    }
}
