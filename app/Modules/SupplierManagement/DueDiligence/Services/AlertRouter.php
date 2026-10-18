<?php

namespace App\Modules\SupplierManagement\DueDiligence\Services;

use App\Core\User\Models\User;
use App\Modules\SupplierManagement\DueDiligence\Support\RoleSlot;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierSetting;
use App\Modules\SupplierManagement\Notifications\SupplierAlertNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Decides who hears about what.
 *
 * The flags and the inbox already existed; what did not was anything that decides a critical
 * sanctions match should wake somebody while a changed postal code should not. Without that, every
 * alert is equally loud, which in practice means every alert is equally ignored — and the queue
 * becomes something people visit rather than something that reaches them.
 *
 * Routing is by severity to role slots, configurable per tenant, with defaults that escalate:
 * low goes to the person who owns the supplier, critical goes up to the executive. Everyone at or
 * below the routed slot is NOT notified — seniority means a Compliance Officer can act on a
 * procurement alert, not that they should receive every one of them.
 */
class AlertRouter
{
    /**
     * Who hears about each severity, by default.
     *
     * The shape matters more than the exact mapping, which a tenant can change: something at every
     * level, and nothing that sends a low-severity address change to an executive.
     */
    public const DEFAULTS = [
        'low' => [RoleSlot::PROCUREMENT_OFFICER],
        'medium' => [RoleSlot::PROCUREMENT_OFFICER, RoleSlot::PROCUREMENT_MANAGER],
        'high' => [RoleSlot::PROCUREMENT_MANAGER, RoleSlot::COMPLIANCE_OFFICER],
        'critical' => [RoleSlot::COMPLIANCE_OFFICER, RoleSlot::EXECUTIVE],
    ];

    public function __construct(private readonly RoleMap $roles) {}

    /**
     * Send an alert to whoever this severity routes to.
     *
     * @param  string  $kind  screening | change | finding | case | banking
     * @return int how many people were told
     */
    public function dispatch(Supplier $supplier, string $kind, string $severity, string $summary, array $context = []): int
    {
        $recipients = $this->recipients($severity);

        if ($recipients->isEmpty()) {
            return 0;
        }

        Notification::send($recipients, new SupplierAlertNotification(
            supplier: $supplier,
            kind: $kind,
            severity: $severity,
            summary: $summary,
            context: $context,
        ));

        return $recipients->count();
    }

    /**
     * The people a severity routes to.
     *
     * Deduplicated by user, because a small client with one person in three slots should be told
     * once rather than three times — the fastest way to make somebody filter your alerts into a
     * folder they never open.
     *
     * @return Collection<int, User>
     */
    public function recipients(string $severity): Collection
    {
        return collect($this->slotsFor($severity))
            ->flatMap(fn (string $slot) => $this->roles->holders($slot))
            ->unique('id')
            ->values();
    }

    /** @return array<int, string> */
    public function slotsFor(string $severity): array
    {
        $configured = SupplierSetting::query()->first()->alert_routing ?? [];

        $slots = $configured[$severity] ?? self::DEFAULTS[$severity] ?? self::DEFAULTS['medium'];

        // A tenant that maps a severity to nothing has switched that severity off, which is a real
        // choice — but an unknown slot name is a typo, and silently routing to nobody would hide it.
        return array_values(array_filter((array) $slots, fn ($slot) => RoleSlot::exists($slot)));
    }

    /** The routing table, for the settings screen. */
    public function configuration(): array
    {
        return collect(['critical', 'high', 'medium', 'low'])
            ->map(fn (string $severity) => [
                'severity' => $severity,
                'slots' => $this->slotsFor($severity),
                'recipients' => $this->recipients($severity)->count(),
                'is_default' => ! isset((SupplierSetting::query()->first()->alert_routing ?? [])[$severity]),
            ])
            ->all();
    }

    public function save(array $routing): void
    {
        $clean = [];

        foreach (['critical', 'high', 'medium', 'low'] as $severity) {
            $slots = array_values(array_filter(
                (array) ($routing[$severity] ?? []),
                fn ($slot) => RoleSlot::exists($slot)
            ));

            $clean[$severity] = $slots;
        }

        SupplierSetting::query()->firstOrCreate([])->update(['alert_routing' => $clean]);
    }
}
