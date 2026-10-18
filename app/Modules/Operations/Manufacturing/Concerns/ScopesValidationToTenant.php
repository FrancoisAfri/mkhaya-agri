<?php

namespace App\Modules\Operations\Manufacturing\Concerns;

use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Validation rules that cannot be satisfied by another tenant's rows.
 *
 * Laravel's `exists` rule runs a raw query, so it never passes through the company scope. On its
 * own it answers "is there a row with this id anywhere on the platform?", and a request carrying
 * another tenant's production line id is a request about a row that exists. Row-level security
 * would stop the foreign row being *seen* if the connection enforced it -- but a superuser or
 * table-owner connection ignores RLS entirely, which describes many self-hosted installs, and a
 * tenancy boundary that depends on how the database user was provisioned is not a boundary.
 *
 * Every foreign key a request can supply goes through here.
 */
trait ScopesValidationToTenant
{
    protected function tenantExists(string $table, string $column = 'id', bool $softDeletes = false): Exists
    {
        $rule = Rule::exists($table, $column);

        $companyId = app(CurrentCompany::class)->id() ?? request()->user()?->company_id;

        // A platform administrator has no tenant of their own to narrow to.
        if ($companyId !== null) {
            $rule->where('company_id', $companyId);
        }

        if ($softDeletes) {
            $rule->whereNull('deleted_at');
        }

        return $rule;
    }
}
