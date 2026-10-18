<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A contract template belonging to Project Management.
 *
 * PM keeps its own library rather than reading Contract Management's, because the modules are sold
 * separately and a tenant may own only this one. Where they own both, templates are *imported* —
 * copied in, then owned here — so that editing one never rewrites the other module's original and
 * dropping that module never takes the wording away.
 *
 * These are project templates: subcontracts, appointments, plant hire. The company's employment
 * contracts and its supplier agreements are Contract Management's subject, not this one's.
 */
class ContractTemplate extends Model
{
    use BelongsToCompany, HasPublicUuid, SoftDeletes;

    protected $table = 'pm_contract_templates';

    /** Where the wording came from. A screen has to be able to say. */
    public const SOURCES = ['own', 'seeded', 'imported'];

    protected $fillable = [
        'uuid', 'company_id', 'name', 'summary', 'category', 'body',
        'source', 'source_ref', 'is_active', 'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * A contract with no tenant is a bug, never a record shared with everybody.
     *
     * Row level security matches `company_id = <session>`, and NULL never equals anything — so an
     * unstamped contract is invisible to every tenant user while still being visible to a platform
     * admin who has not picked a company. Two live contracts reached exactly that state: they
     * looked fine to the person who made them and did not exist for anyone else.
     */
    public static function requiresCompany(): bool
    {
        return true;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id'         => $this->uuid,
            'name'       => $this->name,
            'summary'    => $this->summary,
            'category'   => $this->category,
            'source'     => $this->source,
            'is_active'  => (bool) $this->is_active,
            'updated_at' => optional($this->updated_at)->toDateTimeString(),
        ];
    }
}
