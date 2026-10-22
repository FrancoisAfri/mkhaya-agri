<?php

namespace App\Modules\Agri\Services;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\CarbonDocument;
use App\Modules\Agri\Models\CarbonExport;
use App\Modules\Agri\Models\CarbonIssuance;
use App\Modules\Agri\Models\CarbonMonitoring;
use App\Modules\Agri\Models\CarbonPeriod;
use App\Modules\Agri\Models\CarbonRegistration;
use App\Modules\Agri\Models\CarbonTransaction;
use App\Modules\Agri\Models\EnvironmentRecord;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmField;
use App\Modules\Agri\Support\UiLabels;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use ZipStream\OperationMode;
use ZipStream\ZipStream;

/**
 * A carbon project's life with a registry, kept by hand: its listing (Verra VCS, Gold Standard or
 * another programme), status from listed to issuing, monitoring periods and the data measured in
 * them, issuances by vintage with the share withheld in the registry's buffer pool, and the credits
 * later retired or transferred.
 *
 * Neither Verra nor Gold Standard offers an API for submissions — a project is submitted, validated
 * and verified through the registry's own portal and a validation/verification body — so nothing
 * here talks to a registry. What it does is put together the package the submission and the
 * verifier ask for: a project summary (PDF), the monitoring data per period (CSV), the issuance and
 * transaction ledger, and every evidence document already attached, in one zip kept in the database.
 */
class CarbonRegistry
{
    public const REGISTRIES = ['verra_vcs' => 'Verra VCS', 'gold_standard' => 'Gold Standard', 'other' => 'Other'];

    public const STATUSES = ['listed', 'under_validation', 'registered', 'issuing'];

    public const PERIOD_STATUSES = ['monitoring', 'submitted', 'verified', 'issued'];

    public const DOCUMENT_KINDS = ['pdd', 'validation_report', 'monitoring_report', 'verification_report', 'evidence', 'other'];

    /** Farm records that are monitoring data for a land-based project, put beside the project's own measurements. */
    private const MONITORED_KINDS = ['soil', 'fuel', 'energy', 'water', 'erosion', 'rehabilitation', 'biodiversity'];

    public function register(EnvironmentRecord $project, array $data, User $actor): CarbonRegistration
    {
        if ($project->kind !== 'carbon_project') {
            throw ValidationException::withMessages(['record' => 'Only a carbon project is listed with a registry.']);
        }
        if (CarbonRegistration::query()->where('environment_record_id', $project->id)->exists()) {
            throw ValidationException::withMessages(['record' => 'That project is already tracked with a registry.']);
        }
        $this->checkRegistry($data);

        return CarbonRegistration::create($this->fields($data) + ['company_id' => $project->company_id, 'farm_id' => $project->farm_id,
            'environment_record_id' => $project->id, 'methodology' => $data['methodology'] ?? $project->methodology, 'status' => $data['status'] ?? 'listed',
            'status_on' => $data['status_on'] ?? today()->toDateString(), 'created_by' => $actor->id]);
    }

    public function update(CarbonRegistration $reg, array $data): CarbonRegistration
    {
        $this->checkRegistry($data + ['registry' => $reg->registry]);
        $changed = isset($data['status']) && $data['status'] !== $reg->status;
        $reg->update($this->fields($data) + ($changed ? ['status' => $data['status'], 'status_on' => $data['status_on'] ?? today()->toDateString()] : []));

        return $reg;
    }

    public function addPeriod(CarbonRegistration $reg, array $data): CarbonPeriod
    {
        $from = Carbon::parse($data['starts_on']);
        $to = Carbon::parse($data['ends_on']);
        if ($to->lte($from)) {
            throw ValidationException::withMessages(['ends_on' => 'A monitoring period ends after it starts.']);
        }
        $overlap = CarbonPeriod::query()->where('registration_id', $reg->id)->whereDate('starts_on', '<', $to)->whereDate('ends_on', '>', $from)->exists();
        if ($overlap) {
            throw ValidationException::withMessages(['starts_on' => 'Monitoring periods of one project do not overlap.']);
        }

        return CarbonPeriod::create(['company_id' => $reg->company_id, 'farm_id' => $reg->farm_id, 'registration_id' => $reg->id, 'starts_on' => $from->toDateString(),
            'ends_on' => $to->toDateString(), 'status' => $data['status'] ?? 'monitoring', 'claimed_t' => $data['claimed_t'] ?? null, 'notes' => $data['notes'] ?? null]);
    }

    public function updatePeriod(CarbonPeriod $period, array $data): CarbonPeriod
    {
        if (($data['status'] ?? null) === 'verified' && (blank($data['verifier'] ?? $period->verifier) || ($data['verified_t'] ?? $period->verified_t) === null)) {
            throw ValidationException::withMessages(['status' => 'A period is verified only with the verifier and the verified quantity.']);
        }
        $period->update(array_intersect_key($data, array_flip(['status', 'claimed_t', 'verified_t', 'verifier', 'verified_on', 'notes'])));

        return $period;
    }

    /** @param list<array{measured_on: string, parameter: string, value: float, unit: string, field_id?: ?int, method?: ?string, source?: ?string}> $rows */
    public function addMonitoring(CarbonPeriod $period, array $rows, User $actor): int
    {
        $n = 0;
        foreach ($rows as $r) {
            $on = Carbon::parse($r['measured_on']);
            if ($on->lt($period->starts_on) || $on->gt($period->ends_on)) {
                throw ValidationException::withMessages(['measured_on' => "A value measured on {$on->toDateString()} is outside the period."]);
            }
            CarbonMonitoring::create(['company_id' => $period->company_id, 'farm_id' => $period->farm_id, 'period_id' => $period->id, 'measured_on' => $on->toDateString(),
                'parameter' => mb_strimwidth($r['parameter'], 0, 120), 'value' => $r['value'], 'unit' => mb_strimwidth($r['unit'], 0, 30), 'field_id' => $r['field_id'] ?? null,
                'method' => $r['method'] ?? null, 'source' => $r['source'] ?? null, 'recorded_by' => $actor->id]);
            $n++;
        }

        return $n;
    }

    public function addIssuance(CarbonRegistration $reg, array $data): CarbonIssuance
    {
        if (! in_array($reg->status, ['registered', 'issuing'], true)) {
            throw ValidationException::withMessages(['registration' => 'Credits are issued only to a registered project.']);
        }
        $period = ! empty($data['period_id']) ? CarbonPeriod::query()->where('registration_id', $reg->id)->findOrFail($data['period_id']) : null;
        $qty = (float) $data['quantity_t'];
        $buffer = array_key_exists('buffer_t', $data) && $data['buffer_t'] !== null ? (float) $data['buffer_t'] : round($qty * $reg->buffer_pct / (100 - min(99.0, $reg->buffer_pct)), 3);
        if ($period && $period->verified_t !== null) {
            $already = (float) CarbonIssuance::query()->where('period_id', $period->id)->sum(DB::raw('quantity_t + buffer_t'));
            if ($already + $qty + $buffer > $period->verified_t + 0.0005) {
                throw ValidationException::withMessages(['quantity_t' => sprintf('Issued and buffer (%s t) would exceed what was verified for the period (%s t).',
                    round($already + $qty + $buffer, 3), round($period->verified_t, 3))]);
            }
        }

        return DB::transaction(function () use ($reg, $data, $period, $qty, $buffer) {
            $i = CarbonIssuance::create(['company_id' => $reg->company_id, 'farm_id' => $reg->farm_id, 'registration_id' => $reg->id, 'period_id' => $period?->id,
                'vintage' => (int) $data['vintage'], 'quantity_t' => $qty, 'buffer_t' => $buffer, 'serial_start' => $data['serial_start'] ?? null,
                'serial_end' => $data['serial_end'] ?? null, 'issued_on' => $data['issued_on']]);
            $period?->update(['status' => 'issued']);
            if ($reg->status === 'registered') {
                $reg->update(['status' => 'issuing', 'status_on' => $data['issued_on']]);
            }

            return $i;
        });
    }

    public function addTransaction(CarbonIssuance $issuance, array $data, User $actor): CarbonTransaction
    {
        if (! in_array($data['kind'], ['retirement', 'transfer'], true)) {
            throw ValidationException::withMessages(['kind' => 'Credits are retired or transferred.']);
        }
        $left = $this->available($issuance);
        if ((float) $data['quantity_t'] > $left + 0.0005) {
            throw ValidationException::withMessages(['quantity_t' => sprintf('Only %s t of this issuance is still held.', round($left, 3))]);
        }
        if ($data['kind'] === 'transfer' && blank($data['counterparty'] ?? null)) {
            throw ValidationException::withMessages(['counterparty' => 'Say which registry account the credits went to.']);
        }

        return CarbonTransaction::create(['company_id' => $issuance->company_id, 'farm_id' => $issuance->farm_id, 'issuance_id' => $issuance->id, 'kind' => $data['kind'],
            'quantity_t' => $data['quantity_t'], 'transacted_on' => $data['transacted_on'], 'counterparty' => $data['counterparty'] ?? null,
            'beneficiary' => $data['beneficiary'] ?? null, 'serial_start' => $data['serial_start'] ?? null, 'serial_end' => $data['serial_end'] ?? null,
            'reason' => $data['reason'] ?? null, 'created_by' => $actor->id]);
    }

    public function available(CarbonIssuance $issuance): float
    {
        return round($issuance->quantity_t - (float) CarbonTransaction::query()->where('issuance_id', $issuance->id)->sum('quantity_t'), 3);
    }

    /** @param array{original_name: string, mime: string, data: string, size?: int} $file a file as FileStorage keeps it (base64) */
    public function addDocument(CarbonRegistration $reg, string $kind, array $file, ?int $periodId, User $actor): CarbonDocument
    {
        if (! in_array($kind, self::DOCUMENT_KINDS, true)) {
            throw ValidationException::withMessages(['kind' => 'Choose what kind of document it is.']);
        }

        return CarbonDocument::create(['company_id' => $reg->company_id, 'farm_id' => $reg->farm_id, 'registration_id' => $reg->id,
            'period_id' => $periodId ? CarbonPeriod::query()->where('registration_id', $reg->id)->findOrFail($periodId)->id : null, 'kind' => $kind,
            'name' => mb_strimwidth($file['original_name'], 0, 200), 'mime' => $file['mime'], 'size' => (int) ($file['size'] ?? strlen(base64_decode($file['data']))),
            'data' => $file['data'], 'uploaded_by' => $actor->id]);
    }

    /** @return array{issued: float, buffer: float, retired: float, transferred: float, held: float} */
    public function balance(CarbonRegistration $reg): array
    {
        $iss = CarbonIssuance::query()->where('registration_id', $reg->id)->get();
        $tx = CarbonTransaction::query()->whereIn('issuance_id', $iss->pluck('id'))->get();
        $issued = (float) $iss->sum('quantity_t');

        return ['issued' => round($issued, 3), 'buffer' => round((float) $iss->sum('buffer_t'), 3), 'retired' => round((float) $tx->where('kind', 'retirement')->sum('quantity_t'), 3),
            'transferred' => round((float) $tx->where('kind', 'transfer')->sum('quantity_t'), 3), 'held' => round($issued - (float) $tx->sum('quantity_t'), 3)];
    }

    // ── The registry-ready package ──────────────────────────────────────────────────────────

    /**
     * Build the submission package for the project (or one monitoring period of it) and keep it:
     *
     *   project-summary.pdf              the project, its registry listing, periods, issuances, buffer
     *   monitoring/<start>_<end>.csv     every value measured in each period, with the farm's own
     *                                    environment records for the period beside them
     *   ledger/issuances.csv, ledger/transactions.csv
     *   evidence/…                        the project's verification evidence and every document attached
     *   manifest.json                     each file with its size and SHA-256
     *
     * The zip is built in memory and stored base64 in the database; nothing is written to disk.
     */
    public function export(CarbonRegistration $reg, ?CarbonPeriod $only, User $actor): CarbonExport
    {
        $project = EnvironmentRecord::query()->findOrFail($reg->environment_record_id);
        $farm = Farm::query()->findOrFail($reg->farm_id);
        $periods = CarbonPeriod::query()->where('registration_id', $reg->id)->when($only, fn ($q) => $q->whereKey($only->id))->orderBy('starts_on')->get();
        $files = [];

        $files['project-summary.pdf'] = $this->summaryPdf($reg, $project, $farm, $periods);
        $fields = FarmField::query()->where('farm_id', $farm->id)->pluck('code', 'id');
        foreach ($periods as $p) {
            $files['monitoring/'.$p->starts_on->toDateString().'_'.$p->ends_on->toDateString().'.csv'] = $this->monitoringCsv($p, $farm, $fields);
        }
        $issuances = CarbonIssuance::query()->where('registration_id', $reg->id)->when($only, fn ($q) => $q->where('period_id', $only->id))->orderBy('issued_on')->get();
        $files['ledger/issuances.csv'] = $this->csv(['vintage', 'issued_on', 'quantity_tco2e', 'buffer_tco2e', 'serial_start', 'serial_end', 'monitoring_period'],
            $issuances->map(fn ($i) => [$i->vintage, $i->issued_on->toDateString(), $i->quantity_t, $i->buffer_t, $i->serial_start, $i->serial_end,
                ($pp = $periods->firstWhere('id', $i->period_id)) ? $pp->starts_on->toDateString().'/'.$pp->ends_on->toDateString() : ''])->all());
        $files['ledger/transactions.csv'] = $this->csv(['kind', 'date', 'quantity_tco2e', 'vintage', 'serial_start', 'serial_end', 'counterparty_account', 'beneficiary', 'reason'],
            CarbonTransaction::query()->whereIn('issuance_id', $issuances->pluck('id'))->orderBy('transacted_on')->get()
                ->map(fn ($t) => [$t->kind, $t->transacted_on->toDateString(), $t->quantity_t, $issuances->firstWhere('id', $t->issuance_id)?->vintage, $t->serial_start,
                    $t->serial_end, $t->counterparty, $t->beneficiary, $t->reason])->all());

        $names = [];
        $unique = function (string $name) use (&$names) {
            $base = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) ?: 'document';
            $n = $base;
            for ($i = 2; isset($names[$n]); $i++) {
                $n = pathinfo($base, PATHINFO_FILENAME)."-{$i}".(pathinfo($base, PATHINFO_EXTENSION) ? '.'.pathinfo($base, PATHINFO_EXTENSION) : '');
            }
            $names[$n] = true;

            return $n;
        };
        $evidence = EnvironmentRecord::query()->whereKey($project->id)->value('evidence_data');
        if ($evidence) {
            $files['evidence/'.$unique('verification-'.($project->evidence_name ?: 'evidence'))] = base64_decode($evidence);
        }
        foreach (CarbonDocument::query()->where('registration_id', $reg->id)->when($only, fn ($q) => $q->where(fn ($q) => $q->whereNull('period_id')->orWhere('period_id', $only->id)))
            ->orderBy('id')->get()->makeVisible('data') as $d) {
            $files['evidence/'.$d->kind.'/'.$unique($d->name)] = base64_decode($d->data);
        }
        $manifest = collect($files)->map(fn ($bytes, $name) => ['file' => $name, 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)])->values()->all();
        $files['manifest.json'] = json_encode(['project' => $project->description, 'registry' => self::REGISTRIES[$reg->registry] ?? $reg->registry,
            'registry_project_id' => $reg->registry_project_id, 'generated_at' => now()->toIso8601String(), 'period' => $only ? [$only->starts_on->toDateString(), $only->ends_on->toDateString()] : null,
            'note' => UiLabels::get('agri.carbon.no_api'), 'files' => $manifest], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $zip = $this->zip($files);
        $name = sprintf('carbon-%s-%s%s.zip', preg_replace('/[^A-Za-z0-9-]+/', '-', strtolower($reg->registry_project_id ?: 'project-'.$reg->id)),
            now()->format('Ymd-His'), $only ? '-'.$only->starts_on->format('Ymd') : '');

        return CarbonExport::create(['company_id' => $reg->company_id, 'farm_id' => $reg->farm_id, 'registration_id' => $reg->id, 'period_id' => $only?->id,
            'file_name' => $name, 'size' => strlen($zip), 'sha256' => hash('sha256', $zip), 'contents' => array_keys($files), 'data' => base64_encode($zip), 'created_by' => $actor->id]);
    }

    /** @param array<string, string> $files name => bytes */
    private function zip(array $files): string
    {
        $out = fopen('php://memory', 'w+b');
        $zip = new ZipStream(operationMode: OperationMode::NORMAL, outputStream: $out, sendHttpHeaders: false, defaultEnableZeroHeader: false);
        foreach ($files as $name => $bytes) {
            $zip->addFile(fileName: $name, data: $bytes);
        }
        $zip->finish();
        rewind($out);
        $bytes = (string) stream_get_contents($out);
        fclose($out);

        return $bytes;
    }

    private function monitoringCsv(CarbonPeriod $p, Farm $farm, Collection $fields): string
    {
        $rows = CarbonMonitoring::query()->where('period_id', $p->id)->orderBy('measured_on')->get()
            ->map(fn ($m) => [$m->measured_on->toDateString(), 'project', $m->parameter, $m->value, $m->unit, $fields[$m->field_id] ?? '', $m->method, $m->source])->all();
        foreach (EnvironmentRecord::query()->where('farm_id', $farm->id)->whereIn('kind', self::MONITORED_KINDS)
            ->whereBetween('recorded_on', [$p->starts_on->toDateString(), $p->ends_on->toDateString()])->orderBy('recorded_on')->get() as $e) {
            $rows[] = [$e->recorded_on->toDateString(), 'farm_record', $e->kind.': '.$e->description, $e->quantity, $e->unit, $fields[$e->field_id] ?? '', $e->method,
                $e->verification === 'verified' ? 'verified by '.$e->verifier : 'unverified'];
        }

        usort($rows, fn ($a, $b) => strcmp((string) $a[0], (string) $b[0]));

        return $this->csv(['measured_on', 'origin', 'parameter', 'value', 'unit', 'field', 'method', 'source'], $rows);
    }

    private function csv(array $head, array $rows): string
    {
        $h = fopen('php://memory', 'w+');
        fputcsv($h, $head, ',', '"', '\\');
        foreach ($rows as $r) {
            fputcsv($h, array_map(fn ($v) => is_float($v) ? rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.') : $v, $r), ',', '"', '\\');
        }
        rewind($h);
        $s = (string) stream_get_contents($h);
        fclose($h);

        return $s;
    }

    private function summaryPdf(CarbonRegistration $reg, EnvironmentRecord $project, Farm $farm, Collection $periods): string
    {
        $issuances = CarbonIssuance::query()->where('registration_id', $reg->id)->orderBy('issued_on')->get();
        $html = view('agri.carbon-summary', [
            'reg' => $reg, 'project' => $project, 'farm' => $farm, 'periods' => $periods, 'issuances' => $issuances,
            'balance' => $this->balance($reg), 'registry' => self::REGISTRIES[$reg->registry] === 'Other' ? ($reg->registry_name ?: 'Other') : self::REGISTRIES[$reg->registry],
            'L' => fn (string $k, array $p = []) => UiLabels::get('agri.carbon.'.$k, $p),
            'monitoring' => CarbonMonitoring::query()->whereIn('period_id', $periods->pluck('id'))->get()->groupBy('period_id'),
        ])->render();

        return Pdf::loadHTML($html)->setPaper('a4')->output();
    }

    private function checkRegistry(array $data): void
    {
        if (! array_key_exists($data['registry'] ?? '', self::REGISTRIES)) {
            throw ValidationException::withMessages(['registry' => 'Choose the registry.']);
        }
        if ($data['registry'] === 'other' && blank($data['registry_name'] ?? null)) {
            throw ValidationException::withMessages(['registry_name' => 'Name the registry.']);
        }
        if (isset($data['status']) && ! in_array($data['status'], self::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => 'Unknown registry status.']);
        }
    }

    private function fields(array $data): array
    {
        $out = array_intersect_key($data, array_flip(['registry', 'registry_name', 'registry_project_id', 'methodology', 'proponent', 'crediting_starts_on',
            'crediting_ends_on', 'buffer_pct', 'notes']));
        if (array_key_exists('buffer_pct', $out) && $out['buffer_pct'] === null) {
            unset($out['buffer_pct']);
        }

        return $out;
    }
}
