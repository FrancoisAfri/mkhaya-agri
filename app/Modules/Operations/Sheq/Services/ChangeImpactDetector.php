<?php

namespace App\Modules\Operations\Sheq\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a change invalidates (MOC-2).
 *
 * The requirement says "automatically identify affected HIRA, permits, SOPs, competencies,
 * inductions, environmental controls, emergency plans and quality requirements", and the
 * temptation is to read that as a search problem. It is not. Nothing in this system records that
 * a particular risk assessment depends on a particular pump — no site keeps that graph, and a
 * product that required one before management of change worked would never be used.
 *
 * So this does the honest version: for a change of a given kind at a given site, it lists the
 * records of each kind that are IN FORCE there, and says it found them by looking rather than by
 * knowing. A person then marks each one dealt with or not applicable.
 *
 * That distinction is the whole design, and getting it wrong in either direction is worse than
 * not having the feature:
 *
 * **Claiming to know would be a lie that gets trusted.** A list headed "affected documents" that
 * is really "documents at this site" would have somebody signing off four procedures without
 * reading them, and missing the fifth that mattered because it lives at another site.
 *
 * **Listing nothing would be useless.** A blank checklist asks the requester to remember the
 * eight categories under deadline pressure, which is the exact failure management of change
 * exists to prevent.
 *
 * What is listed is therefore deliberately a candidate set, and the screen says so in those
 * words. NOT_APPLICABLE is a first-class answer with a reason attached, because most of these
 * will not apply and pretending otherwise trains people to clear the list without reading it.
 */
class ChangeImpactDetector
{
    /**
     * The eight kinds MOC-2 lists, each with where it is read from and why it matters.
     *
     * Declared so `ChangeManagementTest` can assert the requirement's list is covered, and so the
     * screen can explain each one. A category silently missing from the detector is the failure
     * this guards: the checklist looks complete and the one thing nobody thought about is absent.
     *
     * @var array<string, array{label:string, note:string, mandatory:bool}>
     */
    public const IMPACT_TYPES = [
        'HIRA' => [
            'label' => 'Risk assessments',
            'note' => 'A change to the work changes the hazards. An assessment that does not '
                .'describe the work as it is now is evidence of nothing.',
            'mandatory' => true,
        ],
        'PERMIT' => [
            'label' => 'Permits to work',
            'note' => 'Open permits were issued against the plant and procedures as they were. '
                .'A live permit that no longer matches the work is a permit for the wrong job.',
            'mandatory' => true,
        ],
        'SOP' => [
            'label' => 'Procedures and SOPs',
            'note' => 'The document people are trained and audited against. Changing the work '
                .'without changing it means every future audit finds a deviation.',
            'mandatory' => true,
        ],
        'COMPETENCY' => [
            'label' => 'Competencies',
            'note' => 'New plant or a new method can need a ticket nobody holds yet — and the '
                .'people doing the work are certified for the old one.',
            'mandatory' => true,
        ],
        'INDUCTION' => [
            'label' => 'Inductions',
            'note' => 'Everybody already inducted was told how it used to be. For a contractor '
                .'change, the incoming firm has not been inducted at all.',
            'mandatory' => true,
        ],
        'ENVIRONMENTAL' => [
            'label' => 'Environmental controls',
            'note' => 'Emissions, effluent, waste streams and the monitoring behind them. The '
                .'one category whose breach is reported to a regulator rather than to you.',
            'mandatory' => true,
        ],
        'EMERGENCY_PLAN' => [
            'label' => 'Emergency plans',
            'note' => 'Assembly points, contacts and procedures. A change to layout, chemicals '
                .'or operating hours can invalidate a plan nobody reads until the day it matters.',
            'mandatory' => true,
        ],
        'QUALITY' => [
            'label' => 'Quality requirements',
            'note' => 'Specifications and customer requirements the change may breach.',
            // Not mandatory by default: reviewing a specification can reasonably follow a
            // change, where re-inducting a crew cannot. Making everything a blocker means the
            // first change is implemented by somebody switching the gate off.
            'mandatory' => false,
        ],
    ];

    /**
     * Which impact kinds a change of each category should be asked about.
     *
     * Every category gets every kind in principle — the point of management of change is that
     * consequences are not where you expect them — but a checklist of eight for a change of
     * operating hours would be cleared without being read. So each category leads with the kinds
     * that genuinely bear on it, and the screen lets somebody add any of the others.
     *
     * @var array<string, list<string>>
     */
    public const CATEGORY_IMPACTS = [
        'EQUIPMENT' => ['HIRA', 'PERMIT', 'SOP', 'COMPETENCY', 'EMERGENCY_PLAN', 'QUALITY'],
        'PROCESS' => ['HIRA', 'SOP', 'COMPETENCY', 'INDUCTION', 'ENVIRONMENTAL', 'QUALITY'],
        'CHEMICAL' => ['HIRA', 'SOP', 'ENVIRONMENTAL', 'EMERGENCY_PLAN', 'COMPETENCY', 'PERMIT'],
        'CONTRACTOR' => ['HIRA', 'INDUCTION', 'COMPETENCY', 'PERMIT', 'SOP'],
        'SITE' => ['HIRA', 'EMERGENCY_PLAN', 'ENVIRONMENTAL', 'INDUCTION', 'PERMIT'],
        'ORGANISATION' => ['SOP', 'EMERGENCY_PLAN', 'COMPETENCY', 'INDUCTION'],
        'LEGISLATION' => ['HIRA', 'SOP', 'COMPETENCY', 'ENVIRONMENTAL', 'QUALITY'],
        'WORK_METHOD' => ['HIRA', 'SOP', 'COMPETENCY', 'PERMIT', 'INDUCTION'],
        'OPERATING_HOURS' => ['EMERGENCY_PLAN', 'HIRA', 'SOP', 'ENVIRONMENTAL'],
    ];

    /**
     * Candidate impacts for one change.
     *
     * @return list<array<string,mixed>>
     */
    public function detect(int $companyId, object $change): array
    {
        $kinds = self::CATEGORY_IMPACTS[strtoupper((string) $change->change_category)]
            ?? array_keys(self::IMPACT_TYPES);

        $found = [];

        foreach ($kinds as $kind) {
            foreach ($this->candidatesFor($companyId, $kind, $change) as $candidate) {
                $found[] = $candidate + [
                    'impact_type' => $kind,
                    'detected_automatically' => true,
                    'is_mandatory' => self::IMPACT_TYPES[$kind]['mandatory'],
                ];
            }
        }

        return $found;
    }

    /** @return list<array<string,mixed>> */
    private function candidatesFor(int $companyId, string $kind, object $change): array
    {
        $siteId = $change->site_id === null ? null : (int) $change->site_id;

        return match ($kind) {
            'HIRA' => $this->rows(
                DB::table('sheq_risk_assessments')
                    ->where('company_id', $companyId)->whereNull('deleted_at')
                    ->where('status', 'APPROVED')
                    /*
                     * Site-specific AND company-wide. A baseline assessment with no site is in
                     * force everywhere, which makes it affected by a change anywhere — and it is
                     * the one most likely to be missed, because it does not appear in a
                     * site-filtered list.
                     */
                    ->when($siteId !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('site_id')
                        ->orWhere('site_id', $siteId)))
                    ->orderByDesc('effective_from')->limit(25)
                    ->get(['id', 'reference', 'title']),
                'SheqRiskAssessment'),

            'PERMIT' => $this->rows(
                DB::table('sheq_permits')
                    ->where('company_id', $companyId)->whereNull('deleted_at')
                    // Open permits only. A closed permit records work that is finished and
                    // cannot be invalidated by a change made afterwards.
                    ->whereIn('status', ['ACTIVE', 'ISSUED', 'SUBMITTED', 'SUSPENDED'])
                    ->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId))
                    ->orderByDesc('id')->limit(25)
                    ->get(['id', 'reference', 'work_description as title']),
                'SheqPermit'),

            /*
             * Published controlled documents, NOT filtered by type.
             *
             * `doc_type` is free text in this schema — forty characters, no vocabulary behind it
             * — so a filter on ['PROCEDURE', 'SOP', ...] would be a guess that silently matches
             * nothing at a tenant whose documents are typed "Procedure" or "SWP" or left blank.
             * A checklist that quietly lists no procedures is worse than one listing a few
             * extras, because the extras get marked not applicable and the silence does not.
             */
            'SOP' => $this->rows(
                DB::table('sheq_documents')
                    ->where('company_id', $companyId)->whereNull('deleted_at')
                    ->where('status', 'PUBLISHED')
                    ->when($siteId !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('site_id')
                        ->orWhere('site_id', $siteId)))
                    ->orderBy('title')->limit(25)
                    ->get(['id', 'doc_number as reference', 'title']),
                'SheqDocument'),

            'COMPETENCY' => $this->rows(
                DB::table('sheq_competencies')
                    ->where('company_id', $companyId)->whereNull('deleted_at')
                    ->where('is_active', true)
                    // The ones that stop work when they lapse. Listing every competency a company
                    // has catalogued would bury the handful a change actually bears on.
                    ->where(fn ($q) => $q->where('blocks_work_when_expired', true)
                        ->orWhere('is_mandatory', true))
                    ->orderBy('title')->limit(25)
                    ->get(['id', 'code as reference', 'title']),
                'SheqCompetency'),

            'INDUCTION' => $this->rows(
                DB::table('sheq_inductions')
                    ->where('company_id', $companyId)->whereNull('deleted_at')
                    ->where('is_active', true)
                    ->when($siteId !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('site_id')
                        ->orWhere('site_id', $siteId)))
                    ->orderBy('id')->limit(25)
                    ->get(['id', 'code as reference', 'title']),
                'SheqInduction'),

            'ENVIRONMENTAL' => $this->rows(
                Schema::hasTable('sheq_environmental_meters')
                    ? DB::table('sheq_environmental_meters')
                        ->where('company_id', $companyId)->whereNull('deleted_at')
                        ->where('is_active', true)
                        ->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId))
                        ->orderBy('name')->limit(25)
                        ->get(['id', 'code as reference', 'name as title'])
                    : collect(),
                'SheqEnvironmentalMeter'),

            'EMERGENCY_PLAN' => $this->rows(
                DB::table('sheq_emergency_plans')
                    ->where('company_id', $companyId)->whereNull('deleted_at')
                    ->where('is_active', true)
                    ->when($siteId !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('site_id')
                        ->orWhere('site_id', $siteId)))
                    ->orderBy('name')->limit(25)
                    ->get(['id', 'emergency_type as reference', 'name as title']),
                'SheqEmergencyPlan'),

            /*
             * Quality has no register of its own in this module, and inventing one here would be
             * worse than saying so. The row is still raised — with no subject — because the
             * question "does this change breach a customer specification" has to be asked, and
             * the honest form of asking it is a line somebody has to answer rather than a list
             * of records that does not exist.
             */
            'QUALITY' => [[
                'subject_type' => null, 'subject_id' => null,
                'subject_reference' => null,
                'subject_title' => 'Any specification or customer requirement this change affects',
            ]],

            default => [],
        };
    }

    /**
     * @param  iterable<object>  $rows
     * @return list<array<string,mixed>>
     */
    private function rows(iterable $rows, string $subjectType): array
    {
        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                'subject_type' => $subjectType,
                'subject_id' => (int) $row->id,
                // Captured as they are now, so the checklist still reads correctly after the
                // underlying record is revised — which is the likely outcome of the change.
                'subject_reference' => isset($row->reference) ? (string) $row->reference : null,
                'subject_title' => isset($row->title) ? (string) $row->title : null,
            ];
        }

        return $out;
    }
}
