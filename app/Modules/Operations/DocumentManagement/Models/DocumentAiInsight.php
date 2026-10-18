<?php

namespace App\Modules\Operations\DocumentManagement\Models;

use App\Core\User\Models\User;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something a model said about a document.
 *
 * `grounded` is the field that matters. An answer the model refused to give because the document
 * does not contain it is not the same as an answer, and storing the two alike makes the feature look
 * more capable than it is — which is how somebody comes to rely on it.
 */
class DocumentAiInsight extends Model
{
    use BelongsToCompany;

    protected $table = 'document_ai_insights';

    protected $guarded = ['id'];

    protected $casts = [
        'grounded' => 'boolean',
        'total_tokens' => 'integer',
        'data' => 'array',
        'applied_at' => 'datetime',
        'dismissed_at' => 'datetime',
    ];

    public const SUMMARY = 'summary';
    public const ANSWER = 'answer';

    /** Fields read off the document, waiting for somebody to accept or reject them. */
    public const FIELDS = 'fields';

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function askedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
