<?php

namespace App\Modules\Finance\Tax\Support;

/**
 * The tax module's permissions (§16). Roles — Tax Administrator, Preparer, Reviewer, Approver,
 * Compliance Officer, Auditor — are built from these in the role editor; none is hard-coded.
 *
 * View, prepare, review, approve and administer are separate so that segregation of duties can be
 * granted, not just enforced. The services additionally refuse self-approval regardless of grants.
 */
final class TaxPermissions
{
    public const VIEW = 'tax.view';

    public const MANAGE_REGISTRATIONS = 'tax.registration.manage';

    public const DRAFT_RULES = 'tax.rule.draft';

    public const APPROVE_RULES = 'tax.rule.approve';

    public const MANAGE_PERIODS = 'tax.period.manage';

    public const REOPEN_PERIODS = 'tax.period.reopen';

    public const PREPARE = 'tax.prepare';

    public const REVIEW = 'tax.review';

    public const APPROVE = 'tax.approve';

    public const RECONCILE = 'tax.reconcile';

    public const SIGN_OFF = 'tax.reconcile.sign_off';

    public const MANAGE_CALENDAR = 'tax.calendar.manage';

    public const VIEW_AUDIT = 'tax.audit.view';

    public const VIEW_PAYROLL = 'tax.payroll.view';

    public const ADMIN = 'tax.admin';

    /** @return list<string> */
    public static function all(): array
    {
        return array_values((new \ReflectionClass(self::class))->getConstants());
    }

    /**
     * Starter role templates (§16). Installed once; an administrator edits them afterwards.
     * System administrators get none of these by default: technical admin is not tax authority.
     *
     * @return array<string, list<string>>
     */
    public static function roleTemplates(): array
    {
        return [
            'Tax Administrator' => [self::VIEW, self::MANAGE_REGISTRATIONS, self::DRAFT_RULES, self::MANAGE_PERIODS, self::MANAGE_CALENDAR, self::VIEW_AUDIT, self::ADMIN],
            'Tax Preparer' => [self::VIEW, self::PREPARE, self::RECONCILE, self::DRAFT_RULES],
            'Tax Reviewer' => [self::VIEW, self::REVIEW, self::RECONCILE, self::SIGN_OFF, self::VIEW_AUDIT],
            'Tax Approver' => [self::VIEW, self::APPROVE, self::APPROVE_RULES, self::SIGN_OFF, self::REOPEN_PERIODS, self::VIEW_AUDIT],
            'Tax Compliance Officer' => [self::VIEW, self::MANAGE_CALENDAR, self::VIEW_AUDIT],
            'Tax Auditor' => [self::VIEW, self::VIEW_AUDIT],
        ];
    }
}
