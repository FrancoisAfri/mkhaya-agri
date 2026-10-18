<?php

namespace App\Modules\Operations\DocumentManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one version of a document says, and how confidently we know it.
 *
 * The status is the point of this table. Text that is absent because the file is a scan, absent
 * because the format is unreadable here, and absent because the page is blank are three different
 * facts, and only the first is worth an administrator's attention.
 */
class DocumentExtraction extends Model
{
    use BelongsToCompany;

    protected $table = 'document_extractions';

    protected $guarded = ['id'];

    protected $casts = [
        'char_count' => 'integer',
        'word_count' => 'integer',
        'page_count' => 'integer',
        'extracted_at' => 'datetime',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'version_id');
    }

    /** Whether there are words here to search or to answer a question from. */
    public function hasText(): bool
    {
        return $this->status === \App\Modules\Operations\DocumentManagement\Services\TextExtractor::EXTRACTED
            && $this->char_count > 0;
    }

    /**
     * Whether somebody should be told about this.
     *
     * A scan nobody can read is worth surfacing; an unreadable video is not, because nobody expected
     * to search inside it.
     */
    public function needsAttention(): bool
    {
        return in_array($this->status, [
            \App\Modules\Operations\DocumentManagement\Services\TextExtractor::NO_TEXT_LAYER,
            \App\Modules\Operations\DocumentManagement\Services\TextExtractor::OCR_UNAVAILABLE,
            \App\Modules\Operations\DocumentManagement\Services\TextExtractor::FAILED,
        ], true);
    }
}
