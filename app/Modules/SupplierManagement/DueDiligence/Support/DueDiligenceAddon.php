<?php

namespace App\Modules\SupplierManagement\DueDiligence\Support;

use App\Modules\Tenancy\Services\CompanyEntitlements;

/**
 * Is the due-diligence add-on switched on for the company in front of us?
 *
 * One place, asked everywhere, because the answer has to be the same in the routes, the panels, the
 * scheduled jobs and the cross-module bridge. A gate that is checked in four places is a gate that
 * is eventually checked in three.
 *
 * A tenant without it keeps Supplier Management exactly as it was: directory, onboarding, KYC,
 * appraisals, sourcing. Nothing breaks and nothing is half-present — the panels are absent rather
 * than empty, because an empty risk panel invites somebody to conclude the supplier is clean.
 */
final class DueDiligenceAddon
{
    public const CODE = 'supplier_due_diligence';

    public static function active(): bool
    {
        return app(CompanyEntitlements::class)->currentAllowsModule(self::CODE);
    }

    /**
     * The reason to show somebody who has hit a due-diligence feature they have not bought.
     *
     * Says what it is and where to get it rather than "access denied", which tells a person nothing
     * they can act on.
     */
    public static function unavailableMessage(): string
    {
        return __('supplier_management.addon.unavailable');
    }
}
