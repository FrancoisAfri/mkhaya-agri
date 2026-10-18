<?php

namespace App\Modules\Finance\Accounting\Models;

use App\Modules\Finance\Accounting\Traits\BelongsToEntity;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A customizable invoice/quote template — accent colour, footer note, default terms &
 * conditions, and logo/bank toggles. A tenant may keep several and mark one default;
 * invoices/quotes reference one via template_id and render with its styling. Tenant-scoped
 * (company_id + FORCE RLS).
 */
class FinDocumentTemplate extends Model
{
    use BelongsToCompany;
    use BelongsToEntity;

    protected $table = 'fin_document_templates';

    protected $fillable = [
        'entity_id', 'uuid', 'company_id', 'name', 'doc_type', 'accent_color', 'use_accent', 'orientation', 'page_size',
        'font', 'layout', 'footer_note', 'terms', 'show_logo', 'show_bank_details', 'is_default',
        'logo_position', 'logo_height',
    ];

    public const ORIENTATIONS = ['portrait', 'landscape'];
    public const PAGE_SIZES = ['a4', 'letter'];
    public const FONTS = ['sans', 'serif', 'mono'];
    /**
     * Genuinely different documents, not one document in four colours.
     *
     *   letterhead  the client block beside the meta, totals right — the everyday invoice
     *   statement   a narrow label column down the left, everything keyed off it
     *   panel       sender and recipient side by side under a banded header
     *   minimal     rules instead of fills, the lightest thing that still reads as a document
     */
    public const LAYOUTS = ['letterhead', 'statement', 'panel', 'minimal'];
    public const DOC_TYPES = ['both', 'invoice', 'quote', 'statement'];

    /**
     * Where the logo sits on the letterhead. 'none' means "shown nowhere" and is kept separate
     * from show_logo=false so a tenant can park the position without losing it.
     */
    public const LOGO_POSITIONS = ['none', 'left', 'center', 'right'];

    /** DomPDF font-family for the template's font key. */
    public function fontFamily(): string
    {
        return match ($this->font) {
            'serif' => 'DejaVu Serif, serif',
            'mono'  => 'DejaVu Sans Mono, monospace',
            default => 'DejaVu Sans, sans-serif',
        };
    }

    /**
     * The starter set: four arrangements, one ink.
     *
     * They used to be the same document four times over with a different accent, which is why
     * picking one changed almost nothing. Each of these puts the information somewhere different;
     * none of them is coloured, because a financial document is read for its numbers.
     */
    public static function starterSet(): array
    {
        return [
            ['name' => 'Letterhead', 'layout' => 'letterhead', 'accent_color' => '#374151', 'is_default' => true],
            ['name' => 'Statement',  'layout' => 'statement',  'accent_color' => '#374151'],
            ['name' => 'Panel',      'layout' => 'panel',      'accent_color' => '#374151'],
            ['name' => 'Minimal',    'layout' => 'minimal',    'accent_color' => '#374151'],
        ];
    }

    /** Layouts retired in favour of the four above, and what each became. */
    public const RENAMED_LAYOUTS = [
        'classic' => 'letterhead',
        'modern'  => 'panel',
        'bold'    => 'statement',
    ];

    protected $casts = [
        'use_accent'        => 'boolean',
        'show_logo'         => 'boolean',
        'show_bank_details' => 'boolean',
        'is_default'        => 'boolean',
        'logo_height'       => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(fn (FinDocumentTemplate $t) => $t->uuid ??= (string) Str::uuid());
    }

    /** The default template for a doc type ('invoice'|'quote'), or the overall default. */
    public static function defaultFor(string $docType): ?self
    {
        return static::query()
            ->whereIn('doc_type', [$docType, 'both'])
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
    }
}
