<?php

namespace App\Modules\Agri\Services;

use App\Modules\Agri\Models\ChemicalProduct;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmMachine;
use App\Modules\Operations\Sheq\Services\LegalRegisterService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2 §12.1: farm compliance, in SHEQ.
 *
 * A farm is a SHEQ site, and its obligations are lines of SHEQ's legal register: the regulatory
 * packs and the compliance schemes (GLOBALG.A.P., SIZA, organic…) the organisation adopts at the
 * site, each line evidenced, computed and reviewed by SHEQ. A document proving a line is a
 * Document Management document linked to it.
 *
 * What the farm adds is evidence only its own records can give, registered with the register so a
 * scheme's clause can simply name it: the farm's machines' statutory inspections, the chemicals'
 * registrations, and whether everyone rostered holds the certificates their shifts need. And the
 * farm alert scan reads the farm's register lines.
 */
class FarmCompliance
{
    public const DUE_SOON_DAYS = 30;

    public const MACHINE_INSPECTION = 'AGRI_MACHINE_INSPECTION';

    public const CHEMICAL_REGISTRATION = 'AGRI_CHEMICAL_REGISTRATION';

    public const OPERATOR_CERTIFICATES = 'AGRI_OPERATOR_CERTIFICATES';

    /** Register the farm's evidence with SHEQ's legal register (at boot). */
    public static function registerEvidence(): void
    {
        LegalRegisterService::registerSource(self::MACHINE_INSPECTION, 'Farm machines\' statutory inspections', fn (int $c, object $line) => self::machines($line));
        LegalRegisterService::registerSource(self::CHEMICAL_REGISTRATION, 'Farm chemicals\' registrations', fn (int $c, object $line) => self::chemicals($c));
        LegalRegisterService::registerSource(self::OPERATOR_CERTIFICATES, 'Certificates of everyone rostered on the farm', fn (int $c, object $line) => self::operators($line));
    }

    /** The farm a register line's site is, if it is a farm. */
    public static function farmOfLine(object $line): ?Farm
    {
        return $line->site_id ? Farm::withoutGlobalScopes()->where('sheq_site_id', $line->site_id)->first() : null;
    }

    private static function byDate(?Carbon $due, string $ok): array
    {
        return match (true) {
            $due === null => ['status' => 'NON_COMPLIANT', 'detail' => 'No date on record.'],
            $due->lt(today()) => ['status' => 'NON_COMPLIANT', 'detail' => "Expired on {$due->toDateString()}."],
            $due->lte(today()->addDays(self::DUE_SOON_DAYS)) => ['status' => 'PARTIAL', 'detail' => "Due by {$due->toDateString()}."],
            default => ['status' => 'COMPLIANT', 'detail' => $ok],
        };
    }

    private static function machines(object $line): array
    {
        $farm = self::farmOfLine($line);
        if (! $farm) {
            return ['status' => 'NOT_ASSESSED', 'detail' => 'This line is not for a farm.'];
        }
        $machines = FarmMachine::withoutGlobalScopes()->where('farm_id', $farm->id)->where('status', '!=', FarmMachine::DISPOSED)->where('inspection_required', true)->get();
        if ($machines->isEmpty()) {
            return ['status' => 'COMPLIANT', 'detail' => 'No machine on the farm needs a statutory inspection.'];
        }
        $missing = $machines->whereNull('inspection_expires_on')->pluck('code');
        if ($missing->isNotEmpty()) {
            return ['status' => 'NON_COMPLIANT', 'detail' => 'No inspection on record for '.$missing->implode(', ').'.'];
        }
        $first = $machines->sortBy(fn ($m) => $m->inspection_expires_on->timestamp)->first();

        return self::byDate($first->inspection_expires_on, 'All '.$machines->count().' machine inspections current.');
    }

    private static function chemicals(int $companyId): array
    {
        $chem = ChemicalProduct::withoutGlobalScopes()->where('company_id', $companyId)->whereNotNull('registration_expires_on')->orderBy('registration_expires_on')->first();

        return $chem ? self::byDate($chem->registration_expires_on, 'Chemical registrations current.')
            : ['status' => 'NON_COMPLIANT', 'detail' => 'No chemical registration dates on the register.'];
    }

    private static function operators(object $line): array
    {
        $farm = self::farmOfLine($line);
        if (! $farm) {
            return ['status' => 'NOT_ASSESSED', 'detail' => 'This line is not for a farm.'];
        }
        $gaps = app(WorkforceAnalytics::class)->certificationGaps($farm, today(), today()->addDays(self::DUE_SOON_DAYS));

        return $gaps ? ['status' => 'NON_COMPLIANT', 'detail' => count($gaps).' rostered shift(s) in the next 30 days lack a required certificate.']
            : ['status' => 'COMPLIANT', 'detail' => 'Everyone rostered holds the certificates their shifts need.'];
    }

    /** Alert conditions for the farm alert scan: the farm's register lines not met, or due for review soon. */
    public function conditions(Farm $farm): array
    {
        if (! $farm->sheq_site_id) {
            return [];
        }
        $out = [];
        $lines = DB::table('sheq_legal_register_lines')->where('company_id', $farm->company_id)->where('site_id', $farm->sheq_site_id)
            ->whereNull('deleted_at')->where('is_applicable', true)->get(['id', 'uuid', 'clause', 'instrument', 'requirement', 'status', 'review_due']);
        foreach ($lines as $l) {
            $dueSoon = $l->review_due && Carbon::parse($l->review_due)->lte(today()->addDays(self::DUE_SOON_DAYS));
            if (! in_array($l->status, ['NON_COMPLIANT', 'PARTIAL', 'REVIEW_REQUIRED'], true) && ! $dueSoon) {
                continue;
            }
            $severity = $l->status === 'NON_COMPLIANT' || ($l->review_due && Carbon::parse($l->review_due)->lt(today())) ? 'critical' : 'warning';
            $out[] = ['kind' => 'compliance', 'severity' => $severity, 'title' => mb_strimwidth("Compliance: {$l->instrument} {$l->clause}", 0, 200, '…'),
                'message' => mb_strimwidth(ucfirst(strtolower(str_replace('_', ' ', $l->status))).': '.$l->requirement, 0, 500, '…'),
                'link' => '/sheq/legal-register?site='.DB::table('sheq_sites')->where('id', $farm->sheq_site_id)->value('uuid'),
                'due_on' => $l->review_due, 'dedupe_key' => "compliance:{$l->id}:{$l->status}"];
        }

        return $out;
    }
}
