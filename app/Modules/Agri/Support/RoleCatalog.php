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

    /** Approving budgets and budget revisions someone else submitted (§6.1). */
    public const APPROVE_BUDGETS = 'agri.budget.approve';

    // ── Module F: machinery ──────────────────────────────────────────────────────────────────
    public const VIEW_MACHINERY = 'agri.machine.view';

    public const MANAGE_MACHINERY = 'agri.machine.manage';

    /** Approving machine bookings and a machine's return to service after a fault (§7.2, §7.3). */
    public const APPROVE_MACHINERY = 'agri.machine.approve';

    /** Issuing fuel from a farm's fuel tank to a machine (§7.4). */
    public const ISSUE_FUEL = 'agri.fuel.issue';

    // ── Phase 2 §8: post-harvest ─────────────────────────────────────────────────────────────
    /** Grading, packing and processing; moving lots; storage readings and corrective actions. */
    public const MANAGE_PACKHOUSE = 'agri.packhouse.manage';

    /** Customer orders, picking, load verification, dispatch and delivery. */
    public const MANAGE_DISPATCH = 'agri.dispatch.manage';

    /** Opening, acting on and closing a recall; writing off recalled produce. */
    public const MANAGE_RECALLS = 'agri.recall.manage';

    // ── Phase 2 §9: sales, contracts, market ─────────────────────────────────────────────────
    public const VIEW_SALES = 'agri.sales.view';

    /** Buyers, price lists, quotes, contract drafts and amendments, disputes, market prices and listings. */
    public const MANAGE_SALES = 'agri.sales.manage';

    /** Approving a buyer contract or an amendment to one that somebody else drafted. */
    public const APPROVE_CONTRACTS = 'agri.contract.approve';

    // ── Phase 2 §10: the assistant's approved knowledge ──────────────────────────────────────
    /** Writing and approving the agronomic references, procedures and regulations advice may quote. */
    public const MANAGE_REFERENCES = 'agri.reference.manage';

    // ── Phase 2 §11: weather ─────────────────────────────────────────────────────────────────
    /** Writing and approving weather thresholds, and fetching the farm's weather. */
    public const MANAGE_WEATHER = 'agri.weather.manage';

    // ── Phase 2 §12: compliance and safety ───────────────────────────────────────────────────
    /** Compliance packs and obligations, and the safety checklists that gate risky work. */
    public const MANAGE_COMPLIANCE = 'agri.compliance.manage';

    // ── Phase 2 §14: integrations ────────────────────────────────────────────────────────────
    /** Seeing every integration message and retrying failed ones. */
    public const MANAGE_INTEGRATIONS = 'agri.integration.manage';

    // ── Phase 2 §16D: outgrowers ─────────────────────────────────────────────────────────────
    public const VIEW_OUTGROWERS = 'agri.outgrower.view';

    /** Growers, their contracts, advances, inspections, collections and settlement drafts. */
    public const MANAGE_OUTGROWERS = 'agri.outgrower.manage';

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

    // ── Phase 2 · workforce, rosters, attendance, piecework, payroll handoff ────────────────
    public const VIEW_WORKFORCE = 'agri.workforce.view';

    /** Registering workers and crews, contracts, consent, badges, suspensions. */
    public const MANAGE_WORKFORCE = 'agri.workforce.manage';

    public const MANAGE_ROSTER = 'agri.roster.manage';

    /** Clocking other people in and out (kiosk, supervisor device) and confirming offline time. */
    public const RECORD_ATTENDANCE = 'agri.attendance.record';

    /** Approving timesheets, corrections and overtime. Never one's own. */
    public const APPROVE_ATTENDANCE = 'agri.attendance.approve';

    public const APPROVE_PIECEWORK = 'agri.piecework.approve';

    /** Preparing and sending approved time and piecework to payroll. */
    public const PAYROLL_HANDOFF = 'agri.payroll.handoff';

    /** Labour rule sets, shift templates and piece rates. */
    public const MANAGE_LABOUR_RULES = 'agri.labour.rules';

    // ── Phase 2 · work orders ────────────────────────────────────────────────────────────────
    public const VIEW_WORK_ORDERS = 'agri.workorder.view';

    public const MANAGE_WORK_ORDERS = 'agri.workorder.manage';

    public const APPROVE_WORK_ORDERS = 'agri.workorder.approve';

    public const VERIFY_WORK_ORDERS = 'agri.workorder.verify';

    // ── Phase 2 · precision crops ────────────────────────────────────────────────────────────
    /** A qualified person's review of an AI-suggested diagnosis, before a treatment is recorded against it. */
    public const REVIEW_DIAGNOSIS = 'agri.scouting.review';

    // ── Phase 2 · livestock ──────────────────────────────────────────────────────────────────
    /** See treatment records (diagnoses, medicines, vet notes) — restricted, as §4.3 asks. */
    public const VIEW_HEALTH = 'agri.health.view';

    /** Verify that a treated animal or group may be sold (a residue test, a vet's release). */
    public const RELEASE_HEALTH = 'agri.health.release';

    // ── Residues, certification and export documents ─────────────────────────────────────────
    /** The MRL register (markets, limits, banned actives, CSV import) and laboratory residue results. */
    public const MANAGE_RESIDUES = 'agri.residue.manage';

    /** Letting a load the residue check stopped go anyway, with a reason (audited). */
    public const OVERRIDE_RESIDUES = 'agri.residue.override';

    /** Export consignments, exporter registration codes and generating the export document pack. */
    public const MANAGE_EXPORTS = 'agri.export.manage';

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
            self::VIEW_FINANCE, self::MANAGE_FINANCE, self::APPROVE_BUDGETS,
            self::VIEW_MACHINERY, self::MANAGE_MACHINERY, self::APPROVE_MACHINERY, self::ISSUE_FUEL, self::MANAGE_PACKHOUSE, self::MANAGE_DISPATCH, self::MANAGE_RECALLS, self::VIEW_SALES, self::MANAGE_SALES, self::APPROVE_CONTRACTS, self::MANAGE_REFERENCES, self::MANAGE_WEATHER, self::MANAGE_COMPLIANCE, self::MANAGE_INTEGRATIONS, self::VIEW_OUTGROWERS, self::MANAGE_OUTGROWERS,
            self::VIEW_TASKS, self::MANAGE_TASKS, self::APPROVE_TASKS,
            self::VIEW_DOCUMENTS, self::MANAGE_DOCUMENTS,
            self::VIEW_REPORTS, self::USE_ASSISTANT,
            self::VIEW_WORKFORCE, self::MANAGE_WORKFORCE, self::MANAGE_ROSTER, self::RECORD_ATTENDANCE, self::APPROVE_ATTENDANCE,
            self::APPROVE_PIECEWORK, self::PAYROLL_HANDOFF, self::MANAGE_LABOUR_RULES,
            self::VIEW_WORK_ORDERS, self::MANAGE_WORK_ORDERS, self::APPROVE_WORK_ORDERS, self::VERIFY_WORK_ORDERS,
            self::REVIEW_DIAGNOSIS, self::VIEW_HEALTH, self::RELEASE_HEALTH,
            self::MANAGE_RESIDUES, self::OVERRIDE_RESIDUES, self::MANAGE_EXPORTS,
        ];
    }

    /**
     * Permissions of other modules a farm role needs for work that runs through them — approving
     * a transfer between farm stores is the warehouse module's transfer approval.
     *
     * @return list<string>
     */
    public static function shared(): array
    {
        return ['wms.transfer.approve'];
    }

    /** Every permission a farm role may be given: the farm's own and the shared ones. @return list<string> */
    public static function everything(): array
    {
        return [...self::all(), ...self::shared()];
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
            self::VIEW_TASKS, self::VIEW_DOCUMENTS, self::VIEW_REPORTS, self::VIEW_WORKFORCE, self::VIEW_WORK_ORDERS];

        return [
            'Farm Owner' => self::everything(),
            'Farm Manager' => array_values(array_diff(self::everything(), [self::ALL_FARMS])),
            'Farm Supervisor' => [...$view, self::RECORD_ACTIVITIES, self::RECORD_HARVEST, self::MANAGE_LIVESTOCK,
                self::RECORD_HEALTH, self::VIEW_HEALTH, self::ISSUE_STOCK, self::MANAGE_TASKS, self::APPROVE_TASKS, self::USE_ASSISTANT,
                self::MANAGE_ROSTER, self::RECORD_ATTENDANCE, self::APPROVE_ATTENDANCE, self::APPROVE_PIECEWORK,
                self::MANAGE_WORK_ORDERS, self::VERIFY_WORK_ORDERS, self::ISSUE_FUEL],
            'Farm Worker' => [self::VIEW_FARMS, self::VIEW_CROPS, self::VIEW_LIVESTOCK, self::VIEW_TASKS,
                self::RECORD_ACTIVITIES, self::RECORD_HARVEST, self::RECORD_HEALTH, self::VIEW_HEALTH, self::VIEW_WORK_ORDERS],
            'Farm Finance Officer' => [...$view, self::VIEW_FINANCE, self::MANAGE_FINANCE, self::APPROVE_BUDGETS, self::RECEIVE_STOCK, self::USE_ASSISTANT,
                self::PAYROLL_HANDOFF],
            'Farm Sales Officer' => [...$view, self::VIEW_SALES, self::MANAGE_SALES, self::MANAGE_DISPATCH, self::VIEW_FINANCE, self::MANAGE_EXPORTS],
            'Packhouse Supervisor' => [...$view, self::MANAGE_PACKHOUSE, self::MANAGE_DISPATCH, self::RECORD_HARVEST, self::ISSUE_STOCK, self::MANAGE_EXPORTS, self::MANAGE_RESIDUES],
            'Farm Workshop Technician' => [...$view, self::MANAGE_MACHINERY, self::RECORD_ACTIVITIES, self::ISSUE_STOCK, self::ISSUE_FUEL],
            'Farm Agronomist' => [...$view, self::PLAN_CROPS, self::RECORD_ACTIVITIES, self::REVIEW_DIAGNOSIS, self::USE_ASSISTANT, self::MANAGE_REFERENCES, self::MANAGE_WEATHER, self::MANAGE_RESIDUES],
            'Farm Viewer' => $view,
        ];
    }
}
