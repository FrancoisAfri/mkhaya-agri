<?php

namespace App\Modules\Tenancy\Exceptions;

use RuntimeException;

/**
 * Something tenant-owned was about to be created with no tenant to own it.
 *
 * Row level security matches `company_id = <session company>`, so a row written with a null one is
 * invisible to every tenant from the moment it is saved — including to the person who just created
 * it, as soon as they land in a company context. It is not a leak; it is worse in a quieter way,
 * because the save appears to have worked.
 *
 * Thrown rather than silently defaulted to some company, because guessing which tenant a record
 * belongs to is how one company's budget ends up in another company's reports.
 */
class TenantContextMissingException extends RuntimeException
{
    public function __construct(public readonly string $model)
    {
        parent::__construct(__('tenancy.no_company_context', ['model' => class_basename($model)]));
    }
}
