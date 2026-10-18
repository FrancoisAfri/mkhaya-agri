<?php

namespace App\Modules\Operations\DocumentManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * What a document is, as distinct from where it is filed.
 *
 * An invoice is an invoice whether it sits under Finance or under the supplier who sent it, so the
 * category travels with the document and the folder says nothing about it. That separation is what
 * lets someone ask for every tax document in the company without knowing how anybody filed them.
 *
 * Rows rather than an enum, because administrators add their own and an enum would make that a
 * deployment.
 */
class DocumentCategory extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $table = 'document_categories';

    protected $guarded = ['id'];

    protected $casts = [
        'workflow_required' => 'boolean',
        'is_system' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'retention_years' => 'integer',
        'archive_after_years' => 'integer',
        'auto_archive' => 'boolean',
        'auto_delete' => 'boolean',
    ];

    /** Whether documents in this category are on a retention schedule at all. */
    public function hasRetentionSchedule(): bool
    {
        return $this->retention_years !== null && $this->retention_years > 0;
    }

    /**
     * The categories every installation starts with.
     *
     * Chosen to cover what a South African organisation actually files rather than to be
     * exhaustive — an administrator adds the rest. Order is the order they appear in, which is
     * roughly how often they are used rather than alphabetical.
     */
    public const DEFAULTS = [
        ['name' => 'Contract', 'slug' => 'contract', 'colour' => '#4F46E5', 'icon' => 'tabler-file-text'],
        ['name' => 'Invoice', 'slug' => 'invoice', 'colour' => '#059669', 'icon' => 'tabler-receipt'],
        ['name' => 'Quote', 'slug' => 'quote', 'colour' => '#0891B2', 'icon' => 'tabler-file-dollar'],
        ['name' => 'Employee Document', 'slug' => 'employee-document', 'colour' => '#7C3AED', 'icon' => 'tabler-user'],
        ['name' => 'Identity Document', 'slug' => 'identity-document', 'colour' => '#DB2777', 'icon' => 'tabler-id'],
        ['name' => 'Tax Document', 'slug' => 'tax-document', 'colour' => '#B45309', 'icon' => 'tabler-percentage'],
        ['name' => 'Supplier Document', 'slug' => 'supplier-document', 'colour' => '#0D9488', 'icon' => 'tabler-truck'],
        ['name' => 'Compliance Document', 'slug' => 'compliance-document', 'colour' => '#DC2626', 'icon' => 'tabler-shield-check'],
        ['name' => 'Policy', 'slug' => 'policy', 'colour' => '#2563EB', 'icon' => 'tabler-book'],
        ['name' => 'Procedure', 'slug' => 'procedure', 'colour' => '#3B82F6', 'icon' => 'tabler-list-check'],
        ['name' => 'Certificate', 'slug' => 'certificate', 'colour' => '#CA8A04', 'icon' => 'tabler-certificate'],
        ['name' => 'Report', 'slug' => 'report', 'colour' => '#6366F1', 'icon' => 'tabler-report'],
        ['name' => 'Legal Document', 'slug' => 'legal-document', 'colour' => '#7E22CE', 'icon' => 'tabler-gavel'],
        ['name' => 'Financial Document', 'slug' => 'financial-document', 'colour' => '#047857', 'icon' => 'tabler-coins'],
        ['name' => 'Payroll Document', 'slug' => 'payroll-document', 'colour' => '#0E7490', 'icon' => 'tabler-cash'],
        ['name' => 'Tender Document', 'slug' => 'tender-document', 'colour' => '#EA580C', 'icon' => 'tabler-briefcase'],
        ['name' => 'Meeting Document', 'slug' => 'meeting-document', 'colour' => '#64748B', 'icon' => 'tabler-users'],
        ['name' => 'General', 'slug' => 'general', 'colour' => '#6B7280', 'icon' => 'tabler-file'],
    ];

    protected static function booted(): void
    {
        static::creating(function (self $category) {
            $category->uuid ??= (string) Str::uuid();
            $category->slug = $category->slug ?: Str::slug($category->name);
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'category_id');
    }

    /**
     * Whether this can be removed.
     *
     * A shipped category stays, because reporting and retention rules name it and a deleted one
     * turns those into silence rather than an error. It can be renamed and deactivated instead,
     * which covers what someone actually wants when they ask to remove it.
     */
    public function isDeletable(): bool
    {
        return ! $this->is_system;
    }

    /** Active categories in the order an administrator arranged them. */
    public function scopeUsable($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('name');
    }
}
