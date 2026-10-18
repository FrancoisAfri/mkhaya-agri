<?php

namespace App\Modules\SupplierManagement\DueDiligence\Support;

/**
 * The four sign-off roles, as slots a tenant maps to its own people.
 *
 * Slots rather than fixed roles because the same workflow has to fit a five-person contractor and a
 * bank. A small client points three slots at one person and gets a workable process with the one
 * control that matters still standing — the raiser cannot approve their own supplier. A bank points
 * each slot at a different team and gets full segregation. Neither had to be given a different
 * product.
 *
 * Mapped per tenant in configuration rather than through Spatie roles, because this platform's roles
 * are still global: a tenant naming its own "Compliance Officer" role must not thereby define that
 * role for every other tenant on the installation.
 */
final class RoleSlot
{
    public const PROCUREMENT_OFFICER = 'procurement_officer';

    public const PROCUREMENT_MANAGER = 'procurement_manager';

    public const COMPLIANCE_OFFICER = 'compliance_officer';

    public const EXECUTIVE = 'executive';

    /**
     * @return array<string, array{label:string, description:string, escalates_to:string|null}>
     */
    public static function all(): array
    {
        return [
            self::PROCUREMENT_OFFICER => [
                'label' => 'Procurement Officer',
                'description' => 'Raises suppliers, gathers their information, and runs the checks. Never decides a case they raised.',
                'escalates_to' => self::PROCUREMENT_MANAGER,
            ],
            self::PROCUREMENT_MANAGER => [
                'label' => 'Procurement Manager',
                'description' => 'Approves and rejects. Cannot approve a supplier they raised, and cannot approve one whose gates are failing without recording an override.',
                'escalates_to' => self::COMPLIANCE_OFFICER,
            ],
            self::COMPLIANCE_OFFICER => [
                'label' => 'Compliance Officer',
                'description' => 'Signs off enhanced due diligence, adjudicates sanctions and PEP matches, suspends and reinstates.',
                'escalates_to' => self::EXECUTIVE,
            ],
            self::EXECUTIVE => [
                'label' => 'Executive',
                'description' => 'Approves critical-risk suppliers and decides blacklisting.',
                'escalates_to' => null,
            ],
        ];
    }

    /**
     * Slots that inherit a slot's authority.
     *
     * Seniority is a ladder: anything a Procurement Officer may do, a Manager may do, and so on up.
     * Without this a client who has mapped one person to the Executive slot finds them unable to do
     * the work of the slots beneath it, which is the sort of rule that gets a system worked around
     * within a week.
     */
    public static function satisfiedBy(string $required): array
    {
        $order = [
            self::PROCUREMENT_OFFICER,
            self::PROCUREMENT_MANAGER,
            self::COMPLIANCE_OFFICER,
            self::EXECUTIVE,
        ];

        $index = array_search($required, $order, true);

        return $index === false ? [$required] : array_slice($order, $index);
    }

    public static function label(string $slot): string
    {
        return self::all()[$slot]['label'] ?? $slot;
    }

    public static function exists(string $slot): bool
    {
        return array_key_exists($slot, self::all());
    }
}
