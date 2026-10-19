<?php

namespace App\Modules\Agri\Support;

/**
 * The Mkhaya Agri permission set.
 *
 * Named constants rather than loose strings: a misspelt permission does not fail, `can()` just
 * returns false and the button quietly disappears. All are prefixed `agri.` because Spatie's
 * Gate::before matches bare ability names, and a short name would collide with a policy method.
 */
final class RoleCatalog
{
    /** Reading farm, field and location details within the farms a person may reach. */
    public const VIEW_FARMS = 'agri.farm.view';

    /** Registering, editing, archiving and reactivating farms. */
    public const MANAGE_FARMS = 'agri.farm.manage';

    /** Creating and maintaining fields and locations on a farm. */
    public const MANAGE_FIELDS = 'agri.field.manage';

    /** Granting, suspending and removing people's access to a farm. */
    public const MANAGE_ACCESS = 'agri.access.manage';

    /**
     * Every farm in the organisation, without a membership on each. For the owner and the
     * organisation administrator; everybody else is confined to the farms they are given.
     */
    public const ALL_FARMS = 'agri.farm.all';

    /** Crop types, varieties, seasons, species and breeds: the organisation's catalogues. */
    public const MANAGE_SETTINGS = 'agri.settings.manage';

    // ── Module B: crops ──────────────────────────────────────────────────────────────────────
    public const VIEW_CROPS = 'agri.crop.view';

    /** Drafting and changing crop plans. */
    public const PLAN_CROPS = 'agri.crop.plan';

    /** Approving a draft plan so work can start on it (separate from drafting it). */
    public const APPROVE_CROPS = 'agri.crop.approve';

    /** Recording field activities, inputs used and scouting observations. */
    public const RECORD_ACTIVITIES = 'agri.activity.record';

    public const RECORD_HARVEST = 'agri.harvest.record';

    // ── Module C: livestock ──────────────────────────────────────────────────────────────────
    public const VIEW_LIVESTOCK = 'agri.livestock.view';

    /** Registering animals and groups, movements, weights, births and exits. */
    public const MANAGE_LIVESTOCK = 'agri.livestock.manage';

    /** Vaccinations, treatments and other health records, including withdrawal periods. */
    public const RECORD_HEALTH = 'agri.livestock.health';

    // ── Module D: inventory and procurement ─────────────────────────────────────────────────
    public const VIEW_STOCK = 'agri.stock.view';

    /** Receiving stock into a farm store, opening balances and purchase requisitions. */
    public const RECEIVE_STOCK = 'agri.stock.receive';

    /** Issuing stock from a farm store to a field activity, crop or livestock group. */
    public const ISSUE_STOCK = 'agri.stock.issue';

    // ── Module E: finance ────────────────────────────────────────────────────────────────────
    public const VIEW_FINANCE = 'agri.finance.view';

    /** Budgets, farm expenses and produce sales; posts to the shared ledger. */
    public const MANAGE_FINANCE = 'agri.finance.manage';

    // ── Module F: machinery ──────────────────────────────────────────────────────────────────
    public const VIEW_MACHINERY = 'agri.machine.view';

    public const MANAGE_MACHINERY = 'agri.machine.manage';

    // ── Module G: workforce and tasks ────────────────────────────────────────────────────────
    public const VIEW_TASKS = 'agri.task.view';

    /** Creating and assigning tasks. Anybody may work and submit a task assigned to them. */
    public const MANAGE_TASKS = 'agri.task.manage';

    /** Approving or rejecting submitted work. Never on a task you submitted yourself. */
    public const APPROVE_TASKS = 'agri.task.approve';

    // ── Module J: documents and compliance ──────────────────────────────────────────────────
    public const VIEW_DOCUMENTS = 'agri.document.view';

    public const MANAGE_DOCUMENTS = 'agri.document.manage';

    // ── Module I and H: reports, alerts and the assistant ───────────────────────────────────
    public const VIEW_REPORTS = 'agri.report.view';

    public const USE_ASSISTANT = 'agri.ai.use';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::VIEW_FARMS,
            self::MANAGE_FARMS,
            self::MANAGE_FIELDS,
            self::MANAGE_ACCESS,
            self::ALL_FARMS,
            self::MANAGE_SETTINGS,
            self::VIEW_CROPS, self::PLAN_CROPS, self::APPROVE_CROPS, self::RECORD_ACTIVITIES, self::RECORD_HARVEST,
            self::VIEW_LIVESTOCK, self::MANAGE_LIVESTOCK, self::RECORD_HEALTH,
            self::VIEW_STOCK, self::RECEIVE_STOCK, self::ISSUE_STOCK,
            self::VIEW_FINANCE, self::MANAGE_FINANCE,
            self::VIEW_MACHINERY, self::MANAGE_MACHINERY,
            self::VIEW_TASKS, self::MANAGE_TASKS, self::APPROVE_TASKS,
            self::VIEW_DOCUMENTS, self::MANAGE_DOCUMENTS,
            self::VIEW_REPORTS, self::USE_ASSISTANT,
        ];
    }

    /**
     * Suggested bundles for the farm roles the specification names, used by the demo seeder and
     * offered to tenants as a starting point. A tenant may build any role it likes from all().
     *
     * @return array<string, list<string>>
     */
    public static function suggestedRoles(): array
    {
        $view = [self::VIEW_FARMS, self::VIEW_CROPS, self::VIEW_LIVESTOCK, self::VIEW_STOCK, self::VIEW_MACHINERY,
            self::VIEW_TASKS, self::VIEW_DOCUMENTS, self::VIEW_REPORTS];

        return [
            'Farm Owner' => self::all(),
            'Farm Manager' => array_values(array_diff(self::all(), [self::ALL_FARMS])),
            'Farm Supervisor' => [...$view, self::RECORD_ACTIVITIES, self::RECORD_HARVEST, self::MANAGE_LIVESTOCK,
                self::RECORD_HEALTH, self::ISSUE_STOCK, self::MANAGE_TASKS, self::APPROVE_TASKS, self::USE_ASSISTANT],
            'Farm Worker' => [self::VIEW_FARMS, self::VIEW_CROPS, self::VIEW_LIVESTOCK, self::VIEW_TASKS,
                self::RECORD_ACTIVITIES, self::RECORD_HARVEST, self::RECORD_HEALTH],
            'Farm Finance Officer' => [...$view, self::VIEW_FINANCE, self::MANAGE_FINANCE, self::RECEIVE_STOCK, self::USE_ASSISTANT],
            'Farm Viewer' => $view,
        ];
    }
}
