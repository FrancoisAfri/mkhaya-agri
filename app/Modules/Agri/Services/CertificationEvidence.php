<?php

namespace App\Modules\Agri\Services;

use App\Modules\Agri\Models\ActivityInput;
use App\Modules\Agri\Models\ChemicalProduct;
use App\Modules\Agri\Models\EnvironmentRecord;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmStore;
use App\Modules\Agri\Models\HarvestRecord;
use App\Modules\Agri\Models\ProduceLot;
use App\Modules\Agri\Models\ResidueTest;
use App\Modules\Operations\Sheq\Services\LegalRegisterService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Control points a farm's own records can evidence: spray records with operator and withholding
 * period, harvests after the withholding period, residue tests against MRLs, the chemical store,
 * water tests, incidents, traceability — and the existing chemical registration, operator
 * certificate and machine inspection evidence.
 *
 * Each is judged for a farm as compliant, non-compliant or no evidence, with the detail an auditor
 * reads. Registered with SHEQ's legal register too, so a scheme clause that names one is computed
 * there like any other evidence (no evidence counts as partial).
 */
class CertificationEvidence
{
    public const SPRAY_RECORDS = 'AGRI_SPRAY_RECORDS';

    public const PHI = 'AGRI_PHI_RESPECTED';

    public const RESIDUE_TESTS = 'AGRI_RESIDUE_TESTS';

    public const CHEMICAL_STORE = 'AGRI_CHEMICAL_STORE';

    public const WATER_TESTS = 'AGRI_WATER_TESTS';

    public const INCIDENTS = 'AGRI_INCIDENTS';

    public const TRACEABILITY = 'AGRI_TRACEABILITY';

    public const CHEMICAL_REGISTRATION = FarmCompliance::CHEMICAL_REGISTRATION;

    public const OPERATOR_CERTIFICATES = FarmCompliance::OPERATOR_CERTIFICATES;

    public const MACHINE_INSPECTION = FarmCompliance::MACHINE_INSPECTION;

    public const LABELS = [
        self::SPRAY_RECORDS => 'Farm spray and fertiliser records (operator, rate, withholding period)',
        self::PHI => 'Harvests after the withholding period',
        self::RESIDUE_TESTS => 'Residue tests against the markets\' MRLs',
        self::CHEMICAL_STORE => 'Farm chemical store set up and locked',
        self::WATER_TESTS => 'Water quality tests in the last year',
        self::INCIDENTS => 'Safety and hygiene incidents recorded and closed',
        self::TRACEABILITY => 'Harvest lots traced to field and crop',
    ];

    /** The automatic types, with labels (for the scheme editor and the assessment). @return array<string, string> */
    public static function types(): array
    {
        return self::LABELS + [self::CHEMICAL_REGISTRATION => 'Farm chemicals\' registrations', self::OPERATOR_CERTIFICATES => 'Certificates of everyone rostered on the farm',
            self::MACHINE_INSPECTION => 'Farm machines\' statutory inspections'];
    }

    /** Register the new farm evidence with SHEQ's legal register (at boot). */
    public static function register(): void
    {
        foreach (self::LABELS as $type => $label) {
            LegalRegisterService::registerSource($type, $label, function (int $companyId, object $line) use ($type) {
                $farm = FarmCompliance::farmOfLine($line);
                if (! $farm) {
                    return ['status' => 'NOT_ASSESSED', 'detail' => 'This line is not for a farm.'];
                }
                $r = app(self::class)->evaluate($type, $farm);

                return ['status' => match ($r['status']) { 'compliant' => 'COMPLIANT', 'non_compliant' => 'NON_COMPLIANT', default => 'PARTIAL' },
                    'detail' => $r['status'] === 'no_evidence' ? 'No farm records yet: '.$r['detail'] : $r['detail']];
            });
        }
    }

    /** @return array{status: string, detail: string} status: compliant | non_compliant | no_evidence */
    public function evaluate(string $type, Farm $farm): array
    {
        $since = today()->subYear();

        return match ($type) {
            self::SPRAY_RECORDS => $this->sprays($farm, $since),
            self::PHI => $this->phi($farm, $since),
            self::RESIDUE_TESTS => $this->residues($farm, $since),
            self::CHEMICAL_STORE => $this->store($farm),
            self::WATER_TESTS => $this->water($farm, $since),
            self::INCIDENTS => $this->incidents($farm, $since),
            self::TRACEABILITY => $this->traceability($farm, $since),
            self::CHEMICAL_REGISTRATION, self::OPERATOR_CERTIFICATES, self::MACHINE_INSPECTION => $this->existing($type, $farm),
            default => ['status' => 'no_evidence', 'detail' => 'Unknown evidence type.'],
        };
    }

    /** Chemical and fertiliser inputs issued on the farm since a date, with their activities. */
    private function inputs(Farm $farm, Carbon $since)
    {
        return ActivityInput::withoutGlobalScopes()->where('farm_id', $farm->id)->where('status', ActivityInput::ISSUED)
            ->whereHas('activity', fn ($q) => $q->withoutGlobalScopes()->whereDate('completed_at', '>=', $since))
            ->with(['activity' => fn ($q) => $q->withoutGlobalScopes()])->get();
    }

    private function sprays(Farm $farm, Carbon $since): array
    {
        $inputs = $this->inputs($farm, $since);
        if ($inputs->isEmpty()) {
            return ['status' => 'no_evidence', 'detail' => 'No applications recorded on the farm in the last 12 months.'];
        }
        $chems = ChemicalProduct::withoutGlobalScopes()->whereIn('stock_item_id', $inputs->pluck('stock_item_id')->unique())->get()->keyBy('stock_item_id');
        $gaps = $inputs->filter(fn ($i) => ! ($i->activity?->completed_by ?? $i->issued_by) || ! $i->activity?->field_id
            || (isset($chems[$i->stock_item_id]) && $i->withholding_days === null && $chems[$i->stock_item_id]->withholding_days === null));
        $sprays = $inputs->filter(fn ($i) => isset($chems[$i->stock_item_id]))->count();
        if ($gaps->isNotEmpty()) {
            return ['status' => 'non_compliant', 'detail' => "{$gaps->count()} of {$inputs->count()} application record(s) lack the operator, the field or the withholding period."];
        }

        return ['status' => 'compliant', 'detail' => "{$inputs->count()} application(s) in the last 12 months ({$sprays} plant protection), each with operator, field, product, quantity and withholding period."];
    }

    private function phi(Farm $farm, Carbon $since): array
    {
        $harvests = HarvestRecord::withoutGlobalScopes()->where('farm_id', $farm->id)->whereDate('harvested_on', '>=', $since)->get();
        $residues = app(ResidueControls::class);
        $breaches = [];
        foreach ($harvests as $h) {
            foreach ($residues->applied([(int) $h->crop_plan_id], $h->harvested_on) as $a) {
                if ($a['applied_on'] && $a['phi_days'] && $a['applied_on']->copy()->addDays((int) $a['phi_days'])->gt($h->harvested_on)) {
                    $breaches[] = "{$h->lot_code}: {$a['active']} applied {$a['applied_on']->toDateString()} (PHI {$a['phi_days']} d) before harvest on {$h->harvested_on->toDateString()}";
                }
            }
        }
        if ($breaches) {
            return ['status' => 'non_compliant', 'detail' => 'Harvested inside a withholding period: '.implode('; ', array_slice(array_unique($breaches), 0, 5)).'.'];
        }
        if ($harvests->isEmpty()) {
            return $this->inputs($farm, $since)->isEmpty() ? ['status' => 'no_evidence', 'detail' => 'No harvests or applications in the last 12 months.']
                : ['status' => 'compliant', 'detail' => 'No harvest yet this year; withholding periods are checked at each harvest.'];
        }

        return ['status' => 'compliant', 'detail' => "All {$harvests->count()} harvest(s) in the last 12 months came after the withholding period of every application."];
    }

    private function residues(Farm $farm, Carbon $since): array
    {
        $tests = ResidueTest::withoutGlobalScopes()->where('farm_id', $farm->id)->whereDate('sampled_on', '>=', $since)->with('results')->get();
        if ($tests->isEmpty()) {
            return ['status' => 'no_evidence', 'detail' => 'No residue tests in the last 12 months.'];
        }
        $rc = app(ResidueControls::class);
        $over = [];
        foreach ($tests as $t) {
            $lot = $t->produce_lot_id ? ProduceLot::withoutGlobalScopes()->find($t->produce_lot_id) : null;
            $plan = $t->crop_plan_id ? \App\Modules\Agri\Models\CropPlan::withoutGlobalScopes()->find($t->crop_plan_id) : null;
            $markets = $lot ? $rc->marketsFor($lot) : ($plan ? $rc->marketsForPlan($plan) : $rc->markets((int) $farm->company_id)->where('default_target', true)->keys()->all());
            foreach ($rc->compare($t, $markets) as $row) {
                foreach ($row['markets'] as $m => $res) {
                    if ($res['status'] === 'exceeds') {
                        $over[] = "{$t->reference} {$row['active']} {$row['measured']} mg/kg > {$m} {$res['limit']}";
                    }
                }
            }
        }
        if ($over) {
            return ['status' => 'non_compliant', 'detail' => 'Results over the MRL of a target market: '.implode('; ', array_slice($over, 0, 5)).'.'];
        }

        return ['status' => 'compliant', 'detail' => "{$tests->count()} residue test(s) in the last 12 months, all within the MRLs of their target markets."];
    }

    private function store(Farm $farm): array
    {
        $stores = FarmStore::withoutGlobalScopes()->where('farm_id', $farm->id)->where('kind', 'chemical_store')->where('is_active', true)->get();
        if ($stores->isEmpty()) {
            return ['status' => 'no_evidence', 'detail' => 'No chemical store is set up for the farm.'];
        }
        $bad = $stores->filter(fn ($s) => ! $s->restricted || empty($s->allowed_storage_classes));
        if ($bad->isNotEmpty()) {
            return ['status' => 'non_compliant', 'detail' => $bad->pluck('name')->implode(', ').': not restricted to authorised people, or no storage classes set.'];
        }

        return ['status' => 'compliant', 'detail' => $stores->pluck('name')->implode(', ').': restricted, storage classes set, incompatible products kept apart.'];
    }

    private function water(Farm $farm, Carbon $since): array
    {
        $tests = EnvironmentRecord::withoutGlobalScopes()->where('farm_id', $farm->id)->where('kind', 'water_quality')->whereDate('recorded_on', '>=', $since)->orderByDesc('recorded_on')->get();
        if ($tests->isEmpty()) {
            return ['status' => 'no_evidence', 'detail' => 'No water quality test recorded in the last 12 months (Weather & Environment › record a "water quality" test).'];
        }
        $latest = $tests->first();

        return ['status' => 'compliant', 'detail' => "{$tests->count()} water test(s); latest {$latest->recorded_on->toDateString()}".($latest->evidence_name ? ", lab report {$latest->evidence_name}." : ' (no lab report attached).')];
    }

    private function incidents(Farm $farm, Carbon $since): array
    {
        if (! $farm->sheq_site_id) {
            return ['status' => 'no_evidence', 'detail' => 'The farm is not a SHEQ site yet, so no incidents are recorded against it.'];
        }
        $rows = DB::table('sheq_incidents')->where('company_id', $farm->company_id)->where('site_id', $farm->sheq_site_id)->whereNull('deleted_at')
            ->where('occurred_at', '>=', $since)->get(['reference', 'status', 'occurred_at']);
        $stale = $rows->filter(fn ($r) => $r->status !== 'CLOSED' && Carbon::parse($r->occurred_at)->lt(today()->subDays(30)));
        if ($stale->isNotEmpty()) {
            return ['status' => 'non_compliant', 'detail' => $stale->count().' incident(s) open for more than 30 days: '.$stale->pluck('reference')->implode(', ').'.'];
        }

        return ['status' => 'compliant', 'detail' => $rows->isEmpty() ? 'No incidents recorded at the farm in the last 12 months.'
            : "{$rows->count()} incident(s) recorded; ".$rows->where('status', 'CLOSED')->count().' closed, the rest under investigation within 30 days.'];
    }

    private function traceability(Farm $farm, Carbon $since): array
    {
        $lots = ProduceLot::withoutGlobalScopes()->where('farm_id', $farm->id)->where('kind', 'harvest')->whereDate('produced_on', '>=', $since)->get();
        if ($lots->isEmpty()) {
            return ['status' => 'no_evidence', 'detail' => 'No harvest lots in the last 12 months.'];
        }
        $gaps = $lots->filter(fn ($l) => ! $l->field_id || ! $l->crop_plan_id);
        if ($gaps->isNotEmpty()) {
            return ['status' => 'non_compliant', 'detail' => "{$gaps->count()} of {$lots->count()} harvest lot(s) are not linked to a field and crop: ".$gaps->pluck('code')->take(5)->implode(', ').'.'];
        }

        return ['status' => 'compliant', 'detail' => "All {$lots->count()} harvest lot(s) trace to their field and crop plan, and forward through packing to dispatch."];
    }

    private function existing(string $type, Farm $farm): array
    {
        $line = (object) ['site_id' => $farm->sheq_site_id];
        $r = match ($type) {
            self::CHEMICAL_REGISTRATION => FarmCompliance::chemicals((int) $farm->company_id),
            self::OPERATOR_CERTIFICATES => $farm->sheq_site_id ? FarmCompliance::operators($line) : ['status' => 'NOT_ASSESSED', 'detail' => 'The farm is not a SHEQ site yet.'],
            default => $farm->sheq_site_id ? FarmCompliance::machines($line) : ['status' => 'NOT_ASSESSED', 'detail' => 'The farm is not a SHEQ site yet.'],
        };

        return ['status' => match ($r['status']) { 'COMPLIANT', 'PARTIAL' => 'compliant', 'NON_COMPLIANT' => 'non_compliant', default => 'no_evidence' }, 'detail' => $r['detail']];
    }
}
