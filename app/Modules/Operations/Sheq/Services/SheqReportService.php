<?php

namespace App\Modules\Operations\Sheq\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The module's reports, as a catalogue rather than a page.
 *
 * The reports screen used to be four hard-coded blocks — incidents, actions, hazards, obligations —
 * fixed to the current calendar month, with no way to change the period, no site filter and no
 * export. Everything else the module records was invisible: PPE issued and what it cost, stock on a
 * shelf, who was on site, inspections done, permits open, medicals falling due.
 *
 * The consequence was not that the data was missing. It was that people asked for it by email and
 * somebody ran a query, which is how a compliance module quietly becomes a spreadsheet again.
 *
 * **Every report is a definition, not a method.** A report names its columns and returns rows; the
 * controller renders any of them and exports any of them without knowing which. Adding the next one
 * is an entry in {@see catalogue()}, not another branch in a controller and another table in a Vue
 * file.
 *
 * **Dates are per report, deliberately.** Some reports describe a period — incidents in March.
 * Others describe a state that has no period at all: what is on the shelf right now, whose medical
 * expires next. Filtering the second kind by date returns an empty table and reads as "nothing to
 * report", which is the most dangerous wrong answer a compliance report can give. Those are marked
 * `periodic => false` and ignore the range.
 */
class SheqReportService
{
    /** Reports grouped the way somebody looking for one would think of them. */
    public const GROUPS = ['safety', 'people', 'operations', 'compliance'];

    public function __construct(private readonly ContractorService $contractors) {}

    /**
     * Every report this module can produce.
     *
     * @return array<string, array{group:string, periodic:bool, columns:array<string,string>}>
     */
    public function catalogue(): array
    {
        return [
            // ── Safety ───────────────────────────────────────────────────────────────────────
            'incidents_by_type' => ['group' => 'safety', 'periodic' => true, 'columns' => [
                'incident_type' => 'text', 'total' => 'number', 'reportable' => 'number',
                'days_lost' => 'number']],

            'incidents_detail' => ['group' => 'safety', 'periodic' => true, 'columns' => [
                'reference' => 'text', 'occurred_at' => 'date', 'incident_type' => 'text',
                'actual_severity' => 'text', 'is_reportable' => 'bool', 'days_lost' => 'number',
                'status' => 'status']],

            'hazards_by_status' => ['group' => 'safety', 'periodic' => true, 'columns' => [
                'status' => 'status', 'hazard_category' => 'text', 'total' => 'number']],

            'actions_overdue' => ['group' => 'safety', 'periodic' => false, 'columns' => [
                'reference' => 'text', 'title' => 'text', 'priority' => 'text',
                'due_date' => 'date', 'days_overdue' => 'number', 'status' => 'status']],

            'audit_findings_open' => ['group' => 'safety', 'periodic' => false, 'columns' => [
                'reference' => 'text', 'grade' => 'text', 'clause' => 'text',
                'due_date' => 'date', 'status' => 'status']],

            // ── People ───────────────────────────────────────────────────────────────────────
            'ppe_issued' => ['group' => 'people', 'periodic' => true, 'columns' => [
                'issued_on' => 'date', 'item' => 'text', 'size' => 'text', 'quantity' => 'number',
                'issued_to' => 'text', 'replace_due' => 'date', 'status' => 'status']],

            'ppe_usage_by_item' => ['group' => 'people', 'periodic' => true, 'columns' => [
                'code' => 'text', 'item' => 'text', 'issues' => 'number', 'quantity' => 'number',
                'people' => 'number']],

            'ppe_replacements_due' => ['group' => 'people', 'periodic' => false, 'columns' => [
                'item' => 'text', 'issued_to' => 'text', 'issued_on' => 'date',
                'replace_due' => 'date', 'days_until' => 'number']],

            'ppe_stock' => ['group' => 'people', 'periodic' => false, 'columns' => [
                'code' => 'text', 'item' => 'text', 'size' => 'text', 'site' => 'text',
                'quantity_on_hand' => 'number', 'reorder_level' => 'number', 'is_low' => 'bool']],

            'competency_expiring' => ['group' => 'people', 'periodic' => false, 'columns' => [
                'competency' => 'text', 'holder' => 'text', 'achieved_at' => 'date',
                'expires_at' => 'date', 'days_until' => 'number', 'status' => 'status']],

            'medical_due' => ['group' => 'people', 'periodic' => false, 'columns' => [
                'medical_type' => 'text', 'person' => 'text', 'examined_on' => 'date',
                'expires_on' => 'date', 'days_until' => 'number', 'fitness_outcome' => 'text']],

            'inductions_completed' => ['group' => 'people', 'periodic' => true, 'columns' => [
                'completed_at' => 'date', 'person_name' => 'text', 'score' => 'number',
                'passed' => 'bool', 'expires_on' => 'date']],

            'toolbox_talks' => ['group' => 'people', 'periodic' => true, 'columns' => [
                'held_on' => 'date', 'topic' => 'text', 'site' => 'text',
                'attendee_count' => 'number', 'duration_minutes' => 'number']],

            // ── Operations ───────────────────────────────────────────────────────────────────
            'visitors' => ['group' => 'operations', 'periodic' => true, 'columns' => [
                'reference' => 'text', 'visitor_name' => 'text', 'visitor_company' => 'text',
                'site' => 'text', 'signed_in_at' => 'datetime', 'signed_out_at' => 'datetime',
                'status' => 'status']],

            'visitors_on_site' => ['group' => 'operations', 'periodic' => false, 'columns' => [
                'visitor_name' => 'text', 'visitor_company' => 'text', 'site' => 'text',
                'signed_in_at' => 'datetime', 'hours_on_site' => 'number']],

            'inspections_completed' => ['group' => 'operations', 'periodic' => true, 'columns' => [
                'reference' => 'text', 'completed_at' => 'date', 'site' => 'text',
                'score_percent' => 'number', 'failed_count' => 'number', 'passed' => 'bool']],

            'permits' => ['group' => 'operations', 'periodic' => true, 'columns' => [
                'reference' => 'text', 'permit_type' => 'text', 'site' => 'text',
                'valid_from' => 'datetime', 'valid_to' => 'datetime', 'status' => 'status']],

            'equipment_due' => ['group' => 'operations', 'periodic' => false, 'columns' => [
                'asset_tag' => 'text', 'name' => 'text', 'site' => 'text',
                'next_inspection_due' => 'date', 'days_until' => 'number', 'status' => 'status']],

            'emergency_drills' => ['group' => 'operations', 'periodic' => true, 'columns' => [
                'drill_date' => 'date', 'drill_type' => 'text', 'site' => 'text',
                'participants' => 'number', 'unaccounted' => 'number',
                'evacuation_seconds' => 'number']],

            'environmental_readings' => ['group' => 'operations', 'periodic' => true, 'columns' => [
                'reading_date' => 'date', 'meter' => 'text', 'value' => 'number',
                'co2e_kg' => 'number', 'source' => 'text']],

            // ── Compliance ───────────────────────────────────────────────────────────────────
            'obligations' => ['group' => 'compliance', 'periodic' => false, 'columns' => [
                'label' => 'text', 'due_at' => 'date', 'status' => 'status',
                'days_until' => 'number', 'submitted_at' => 'date']],

            'documents_review_due' => ['group' => 'compliance', 'periodic' => false, 'columns' => [
                'doc_number' => 'text', 'title' => 'text', 'doc_type' => 'text',
                'review_due' => 'date', 'days_until' => 'number', 'status' => 'status']],

            'risk_assessments_review_due' => ['group' => 'compliance', 'periodic' => false, 'columns' => [
                'reference' => 'text', 'title' => 'text', 'ra_type' => 'text',
                'review_due' => 'date', 'days_until' => 'number', 'status' => 'status']],

            'contractors_access' => ['group' => 'compliance', 'periodic' => false, 'columns' => [
                'name' => 'text', 'trade' => 'text', 'access_status' => 'status',
                'workers' => 'number', 'documents' => 'number']],

            /*
             * Employee against contractor, which is the comparison a board asks for and the one
             * the module could not produce.
             *
             * `ContractorService::incidentRollUp()` computed it and nothing called it. The figure
             * matters because a company whose own LTIFR is excellent and whose contractors are
             * getting hurt does not have a good safety record — it has outsourced its injuries,
             * and the combined row is the one a client, an insurer or an inspector will use.
             */
            'incidents_employee_vs_contractor' => ['group' => 'safety', 'periodic' => true, 'columns' => [
                'workforce' => 'text', 'total' => 'number', 'fatalities' => 'number',
                'lost_time' => 'number', 'reportable' => 'number', 'days_lost' => 'number']],

            'incidents_by_contractor' => ['group' => 'safety', 'periodic' => true, 'columns' => [
                'contractor' => 'text', 'incidents' => 'number', 'reportable' => 'number']],
        ];
    }

    /**
     * Run one report.
     *
     * Returns rows only. The caller already has the column list from {@see catalogue()}, and having
     * one place define the columns is what lets the CSV export and the screen agree.
     *
     * @return list<array<string, mixed>>
     */
    public function run(string $key, int $companyId, Carbon $from, Carbon $to, ?int $siteId = null): array
    {
        $definition = $this->catalogue()[$key] ?? null;

        if ($definition === null) {
            return [];
        }

        $rows = $this->{'report'.str_replace('_', '', ucwords($key, '_'))}($companyId, $from, $to, $siteId);

        return array_map(fn ($r) => (array) $r, $rows instanceof Collection
            ? $rows->all()
            : $rows);
    }

    /** Days from today to $date — negative when it has already passed. */
    private function daysUntil(string $column): string
    {
        return "case when {$column} is null then null
                else date_part('day', {$column}::timestamp - current_date::timestamp) end as days_until";
    }

    /** Apply a site filter only where the table has one, so reports without sites still run. */
    private function atSite(mixed $query, string $column, ?int $siteId): mixed
    {
        return $siteId ? $query->where($column, $siteId) : $query;
    }

    // ── Safety ───────────────────────────────────────────────────────────────────────────────

    private function reportIncidentsByType(int $c, Carbon $from, Carbon $to, ?int $site)
    {
        return $this->atSite(DB::table('sheq_incidents')
            ->where('company_id', $c)->whereNull('deleted_at')
            ->whereBetween('occurred_at', [$from, $to]), 'site_id', $site)
            ->selectRaw('incident_type, count(*) as total,
                         count(*) filter (where is_reportable) as reportable,
                         coalesce(sum(days_lost), 0) as days_lost')
            ->groupBy('incident_type')->orderByDesc('total')->get();
    }

    /**
     * The three rows of the comparison, in the order they are read: own staff, contractors, both.
     *
     * Delegated to {@see ContractorService::incidentRollUp()} rather than re-queried here. The
     * roll-up decides what counts as lost time — `outcome` and `treatment_level` disagree often
     * enough that a second implementation would eventually report a different number from the
     * contractor screens, and two safety figures that nearly match is worse than one.
     */
    private function reportIncidentsEmployeeVsContractor(int $c, Carbon $from, Carbon $to, ?int $site)
    {
        $rollUp = $this->contractors->incidentRollUp($c, $from, $to, $site);

        return array_map(fn (string $key) => ['workforce' => match ($key) {
            'employees' => 'Own employees',
            'contractors' => 'Contractors',
            default => 'Combined',
        }] + $rollUp[$key], ['employees', 'contractors', 'combined']);
    }

    /** Which contractors, worst first. A ranking is what makes the comparison actionable. */
    private function reportIncidentsByContractor(int $c, Carbon $from, Carbon $to, ?int $site)
    {
        return $this->contractors->incidentRollUp($c, $from, $to, $site)['by_contractor'];
    }

    private function reportIncidentsDetail(int $c, Carbon $from, Carbon $to, ?int $site)
    {
        return $this->atSite(DB::table('sheq_incidents')
            ->where('company_id', $c)->whereNull('deleted_at')
            ->whereBetween('occurred_at', [$from, $to]), 'site_id', $site)
            ->orderByDesc('occurred_at')
            ->get(['reference', 'occurred_at', 'incident_type', 'actual_severity',
                'is_reportable', 'days_lost', 'status']);
    }

    private function reportHazardsByStatus(int $c, Carbon $from, Carbon $to, ?int $site)
    {
        return $this->atSite(DB::table('sheq_hazards')
            ->where('company_id', $c)->whereNull('deleted_at')
            ->whereBetween('reported_at', [$from, $to]), 'site_id', $site)
            ->selectRaw('status, hazard_category, count(*) as total')
            ->groupBy('status', 'hazard_category')->orderByDesc('total')->get();
    }

    private function reportActionsOverdue(int $c, Carbon $from, Carbon $to, ?int $site)
    {
        return $this->atSite(DB::table('sheq_actions')
            ->where('company_id', $c)->whereNull('deleted_at')
            ->whereNotNull('due_date')->whereDate('due_date', '<', Carbon::today())
            ->whereNotIn('status', ['COMPLETED', 'VERIFIED', 'CANCELLED']), 'site_id', $site)
            ->selectRaw("reference, title, priority, due_date, status,
                         date_part('day', current_date::timestamp - due_date::timestamp) as days_overdue")
            ->orderBy('due_date')->get();
    }

    private function reportAuditFindingsOpen(int $c, Carbon $from, Carbon $to, ?int $site)
    {
        return DB::table('sheq_audit_findings')
            ->where('company_id', $c)->whereNull('deleted_at')
            ->whereNotIn('status', ['CLOSED', 'VERIFIED'])
            ->orderBy('due_date')
            ->get(['reference', 'grade', 'clause', 'due_date', 'status']);
    }

    // ── People ───────────────────────────────────────────────────────────────────────────────

    /** Issues joined to the person, so the report names somebody rather than an id. */
    private function ppeIssueQuery(int $c): mixed
    {
        $query = DB::table('sheq_ppe_issues as s')
            ->join('sheq_ppe_items as i', 'i.id', '=', 's.ppe_item_id')
            ->where('s.company_id', $c)->whereNull('s.deleted_at');

        // hr_people is another module's table: a SHEQ-only install still has to produce this report.
        if (Schema::hasTable('hr_people')) {
            $query->leftJoin('hr_people as p', 'p.id', '=', 's.hr_person_id');
        }

        return $query;
    }

    private function issuedToExpression(): string
    {
        return Schema::hasTable('hr_people')
            ? "trim(coalesce(p.first_name, '') || ' ' || coalesce(p.surname, '')) as issued_to"
            : "'' as issued_to";
    }

    private function reportPpeIssued(int $c, Carbon $from, Carbon $to, ?int $site)
    {
        return $this->ppeIssueQuery($c)
            ->whereBetween('s.issued_on', [$from->toDateString(), $to->toDateString()])
            ->orderByDesc('s.issued_on')
            ->selectRaw('s.issued_on, i.name as item, s.size, s.quantity, s.replace_due, s.status, '
                .$this->issuedToExpression())
            ->get();
    }

    private function reportPpeUsageByItem(int $c, Carbon $from, Carbon $to, ?int $site)
    {
        return DB::table('sheq_ppe_issues as s')
            ->join('sheq_ppe_items as i', 'i.id', '=', 's.ppe_item_id')
            ->where('s.company_id', $c)->whereNull('s.deleted_at')
            ->whereBetween('s.issued_on', [$from->toDateString(), $to->toDateString()])
            ->groupBy('i.code', 'i.name')
            ->orderByDesc(DB::raw('sum(s.quantity)'))
            ->selectRaw('i.code, i.name as item, count(*) as issues,
                         coalesce(sum(s.quantity), 0) as quantity,
                         count(distinct s.hr_person_id) as people')
            ->get();
    }

    private function reportPpeReplacementsDue(int $c, Carbon $from, Carbon $to, ?int $site)
    {
        return $this->ppeIssueQuery($c)
            ->whereNotNull('s.replace_due')->whereNull('s.returned_on')
            ->orderBy('s.replace_due')
            ->selectRaw('i.name as item, s.issued_on, s.replace_due, '
                .$this->issuedToExpression().', '.$this->daysUntil('s.replace_due'))
            ->get();
    }

    private function reportPpeStock(int $c, Carbon $from, Carbon $to, ?int $site)
    {
        return $this->atSite(DB::table('sheq_ppe_stock as k')
            ->join('sheq_ppe_items as i', 'i.id', '=', 'k.ppe_item_id')
            ->leftJoin('sheq_sites as t', 't.id', '=', 'k.site_id')
            ->where('k.company_id', $c)->whereNull('i.deleted_at'), 'k.site_id', $site)
            ->orderBy('i.name')->orderBy('k.size')
            ->selectRaw('i.code, i.name as item, k.size, t.name as site,
                         k.quantity_on_hand, k.reorder_level,
                         (k.quantity_on_hand <= k.reorder_level) as is_low')
            ->get();
    }

    private function reportCompetencyExpiring(int $c, Carbon $from, Carbon $to, ?int $site)
    {
        $query = DB::table('sheq_competency_records as r')
            ->join('sheq_competencies as m', 'm.id', '=', 'r.competency_id')
            ->where('r.company_id', $c)->whereNull('r.deleted_at')
            ->whereNotNull('r.expires_at');

        $holder = "'' as holder";

        if (Schema::hasTable('hr_people')) {
            $query->leftJoin('hr_people as p', 'p.id', '=', 'r.hr_person_id');
            $holder = "trim(coalesce(p.first_name, '') || ' ' || coalesce(p.surname, '')) as holder";
        }

        return $query->orderBy('r.expires_at')
            ->selectRaw("m.title as competency, r.achieved_at, r.expires_at, r.status, {$holder}, "
                .$this->daysUntil('r.expires_at'))
            ->get();
    }

    private function reportMedicalDue(int $c, Carbon $from, Carbon $to, ?int $site)
    {
        $query = DB::table('sheq_medical_records as r')
            ->leftJoin('sheq_medical_types as m', 'm.id', '=', 'r.medical_type_id')
            ->where('r.company_id', $c)->whereNull('r.deleted_at')
            ->whereNotNull('r.expires_on');

        $person = "'' as person";

        if (Schema::hasTable('hr_people')) {
            $query->leftJoin('hr_people as p', 'p.id', '=', 'r.hr_person_id');
            $person = "trim(coalesce(p.first_name, '') || ' ' || coalesce(p.surname, '')) as person";
        }

        return $query->orderBy('r.expires_on')
            ->selectRaw("m.name as medical_type, r.examined_on, r.expires_on, r.fitness_outcome,
                         {$person}, ".$this->daysUntil('r.expires_on'))
            ->get();
    }

    private function reportInductionsCompleted(int $c, Carbon $from, Carbon $to, ?int $site)
    {
        return DB::table('sheq_induction_completions')
            ->where('company_id', $c)
            ->whereBetween('completed_at', [$from, $to])
            ->orderByDesc('completed_at')
            ->get(['completed_at', 'person_name', 'score', 'passed', 'expires_on']);
    }

    private function reportToolboxTalks(int $c, Carbon $from, Carbon $to, ?int $site)
    {
        return $this->atSite(DB::table('sheq_toolbox_talks as k')
            ->leftJoin('sheq_sites as t', 't.id', '=', 'k.site_id')
            ->where('k.company_id', $c)->whereNull('k.deleted_at')
            ->whereBetween('k.held_on', [$from->toDateString(), $to->toDateString()]), 'k.site_id', $site)
            ->orderByDesc('k.held_on')
            ->selectRaw('k.held_on, k.topic, t.name as site, k.attendee_count, k.duration_minutes')
            ->get();
    }

    // ── Operations ───────────────────────────────────────────────────────────────────────────

    private function reportVisitors(int $c, Carbon $from, Carbon $to, ?int $site)
    {
        return $this->atSite(DB::table('sheq_visits as v')
            ->leftJoin('sheq_sites as t', 't.id', '=', 'v.site_id')
            ->where('v.company_id', $c)->whereNull('v.deleted_at')
            ->where(fn ($q) => $q->whereBetween('v.signed_in_at', [$from, $to])
                ->orWhereBetween('v.expected_from', [$from, $to])), 'v.site_id', $site)
            ->orderByDesc(DB::raw('coalesce(v.signed_in_at, v.expected_from)'))
            ->selectRaw('v.reference, v.visitor_name, v.visitor_company, t.name as site,
                         v.signed_in_at, v.signed_out_at, v.status')
            ->get();
    }

    /**
     * Who is on site right now.
     *
     * The one report somebody runs during an evacuation, which is why it ignores the date range
     * entirely: a roll call filtered to last month is worse than no roll call.
     */
    private function reportVisitorsOnSite(int $c, Carbon $from, Carbon $to, ?int $site)
    {
        return $this->atSite(DB::table('sheq_visits as v')
            ->leftJoin('sheq_sites as t', 't.id', '=', 'v.site_id')
            ->where('v.company_id', $c)->whereNull('v.deleted_at')
            ->whereNotNull('v.signed_in_at')->whereNull('v.signed_out_at'), 'v.site_id', $site)
            ->orderBy('v.signed_in_at')
            ->selectRaw('v.visitor_name, v.visitor_company, t.name as site, v.signed_in_at,
                         round(extract(epoch from (now() - v.signed_in_at)) / 3600.0, 1) as hours_on_site')
            ->get();
    }

    private function reportInspectionsCompleted(int $c, Carbon $from, Carbon $to, ?int $site)
    {
        return $this->atSite(DB::table('sheq_inspections as n')
            ->leftJoin('sheq_sites as t', 't.id', '=', 'n.site_id')
            ->where('n.company_id', $c)->whereNull('n.deleted_at')
            ->whereNotNull('n.completed_at')
            ->whereBetween('n.completed_at', [$from, $to]), 'n.site_id', $site)
            ->orderByDesc('n.completed_at')
            ->selectRaw('n.reference, n.completed_at, t.name as site, n.score_percent,
                         n.failed_count, n.passed')
            ->get();
    }

    private function reportPermits(int $c, Carbon $from, Carbon $to, ?int $site)
    {
        return $this->atSite(DB::table('sheq_permits as m')
            ->leftJoin('sheq_sites as t', 't.id', '=', 'm.site_id')
            ->where('m.company_id', $c)->whereNull('m.deleted_at')
            ->whereBetween('m.valid_from', [$from, $to]), 'm.site_id', $site)
            ->orderByDesc('m.valid_from')
            ->selectRaw('m.reference, m.permit_type, t.name as site, m.valid_from, m.valid_to, m.status')
            ->get();
    }

    private function reportEquipmentDue(int $c, Carbon $from, Carbon $to, ?int $site)
    {
        return $this->atSite(DB::table('sheq_equipment as e')
            ->leftJoin('sheq_sites as t', 't.id', '=', 'e.site_id')
            ->where('e.company_id', $c)->whereNull('e.deleted_at')
            ->whereNotNull('e.next_inspection_due'), 'e.site_id', $site)
            ->orderBy('e.next_inspection_due')
            ->selectRaw('e.asset_tag, e.name, t.name as site, e.next_inspection_due, e.status, '
                .$this->daysUntil('e.next_inspection_due'))
            ->get();
    }

    private function reportEmergencyDrills(int $c, Carbon $from, Carbon $to, ?int $site)
    {
        return $this->atSite(DB::table('sheq_emergency_drills as d')
            ->leftJoin('sheq_sites as t', 't.id', '=', 'd.site_id')
            ->where('d.company_id', $c)->whereNull('d.deleted_at')
            ->whereBetween('d.drill_date', [$from->toDateString(), $to->toDateString()]), 'd.site_id', $site)
            ->orderByDesc('d.drill_date')
            ->selectRaw('d.drill_date, d.drill_type, t.name as site, d.participants,
                         d.unaccounted, d.evacuation_seconds')
            ->get();
    }

    private function reportEnvironmentalReadings(int $c, Carbon $from, Carbon $to, ?int $site)
    {
        return DB::table('sheq_environmental_readings as r')
            ->leftJoin('sheq_environmental_meters as m', 'm.id', '=', 'r.meter_id')
            ->where('r.company_id', $c)
            ->whereBetween('r.reading_date', [$from->toDateString(), $to->toDateString()])
            ->orderByDesc('r.reading_date')
            ->selectRaw('r.reading_date, m.name as meter, r.value, r.co2e_kg, r.source')
            ->get();
    }

    // ── Compliance ───────────────────────────────────────────────────────────────────────────

    private function reportObligations(int $c, Carbon $from, Carbon $to, ?int $site)
    {
        return DB::table('sheq_statutory_obligations')
            ->where('company_id', $c)->whereNull('deleted_at')
            ->orderBy('due_at')
            ->selectRaw('label, due_at, status, submitted_at, '.$this->daysUntil('due_at'))
            ->get();
    }

    private function reportDocumentsReviewDue(int $c, Carbon $from, Carbon $to, ?int $site)
    {
        return DB::table('sheq_documents')
            ->where('company_id', $c)->whereNull('deleted_at')
            ->whereNotNull('review_due')
            ->orderBy('review_due')
            ->selectRaw('doc_number, title, doc_type, review_due, status, '.$this->daysUntil('review_due'))
            ->get();
    }

    private function reportRiskAssessmentsReviewDue(int $c, Carbon $from, Carbon $to, ?int $site)
    {
        return $this->atSite(DB::table('sheq_risk_assessments')
            ->where('company_id', $c)->whereNull('deleted_at')
            ->whereNotNull('review_due'), 'site_id', $site)
            ->orderBy('review_due')
            ->selectRaw('reference, title, ra_type, review_due, status, '.$this->daysUntil('review_due'))
            ->get();
    }

    private function reportContractorsAccess(int $c, Carbon $from, Carbon $to, ?int $site)
    {
        return $this->atSite(DB::table('sheq_contractors as k')
            ->where('k.company_id', $c)->whereNull('k.deleted_at'), 'k.site_id', $site)
            ->leftJoin('sheq_contractor_workers as w', function ($join) {
                $join->on('w.contractor_id', '=', 'k.id')->whereNull('w.deleted_at');
            })
            ->leftJoin('sheq_contractor_documents as d', 'd.contractor_id', '=', 'k.id')
            ->groupBy('k.id', 'k.name', 'k.trade', 'k.access_status')
            ->orderBy('k.name')
            ->selectRaw('k.name, k.trade, k.access_status,
                         count(distinct w.id) as workers, count(distinct d.id) as documents')
            ->get();
    }
}
