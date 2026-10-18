<?php

namespace App\Modules\Workflow\Support;

use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Support\Facades\Auth;

/**
 * The company a workflow screen works in: the company being worked in (a platform administrator
 * may have switched into one), else the user's own, else the record's. Platform administrators have
 * no company of their own, so lists of people and departments come from the record they are viewing.
 */
final class WorkflowCompany
{
    public static function id(?int $recordCompanyId = null): ?int
    {
        return $recordCompanyId ?? app(CurrentCompany::class)->id() ?? Auth::user()?->company_id;
    }
}
