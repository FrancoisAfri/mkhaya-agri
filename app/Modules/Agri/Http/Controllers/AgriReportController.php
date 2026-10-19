<?php

namespace App\Modules\Agri\Http\Controllers;

use App\Modules\Agri\Models\ActivityInput;
use App\Modules\Agri\Models\Animal;
use App\Modules\Agri\Models\AnimalHealthRecord;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\FieldActivity;
use App\Modules\Agri\Models\LivestockGroup;
use App\Modules\Agri\Services\Harvests;
use App\Modules\Agri\Support\RoleCatalog as R;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Module I: the standard farm reports, each on screen and as CSV. Every one reads through farm
 * access, so a report never shows a farm the reader cannot open.
 */
class AgriReportController extends AgriController
{
    public const REPORTS = [
        'yield' => ['Crop yield by field', 'Harvested yield per hectare against plan, by crop plan.', R::VIEW_CROPS],
        'activities' => ['Field activity log', 'Every completed field activity with labour, machine hours and input cost.', R::VIEW_CROPS],
        'chemicals' => ['Chemical & fertiliser application register', 'What was applied where, how much, and its withholding period — the spray record auditors ask for.', R::VIEW_CROPS],
        'herd' => ['Livestock register', 'Head count by farm, group and species.', R::VIEW_LIVESTOCK],
        'treatments' => ['Animal treatment register', 'Vaccinations and treatments with products, doses and withdrawal dates.', R::VIEW_LIVESTOCK],
    ];

    public function __construct(private readonly Harvests $harvests) {}

    public function index(Request $request): Response
    {
        $this->allow($request, R::VIEW_REPORTS);
        $report = $request->query('report');
        $user = $request->user();
        $available = collect(self::REPORTS)->filter(fn ($r) => $user->can($r[2]))
            ->map(fn ($r, $k) => ['key' => $k, 'title' => $r[0], 'description' => $r[1]])->values();
        [$from, $to] = $this->period($request);
        $farm = $this->farmFilter($request);

        $data = null;
        if ($report && isset(self::REPORTS[$report]) && $user->can(self::REPORTS[$report][2])) {
            $data = ['key' => $report, 'title' => self::REPORTS[$report][0]] + $this->build($report, $farm?->id, $from, $to);
        }

        return Inertia::render('Modules/Agri/Reports', [
            'reports' => $available, 'data' => $data,
            'filters' => ['report' => $report, 'farm' => $farm?->uuid, 'from' => $from, 'to' => $to],
            'farms' => $this->farmOptions(),
        ]);
    }

    public function export(Request $request, string $report): StreamedResponse
    {
        $this->allow($request, R::VIEW_REPORTS);
        abort_unless(isset(self::REPORTS[$report]), 404);
        $this->allow($request, self::REPORTS[$report][2]);
        [$from, $to] = $this->period($request);
        $farm = $this->farmFilter($request);
        $data = $this->build($report, $farm?->id, $from, $to);

        return response()->streamDownload(function () use ($data) {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_column($data['columns'], 'title'));
            foreach ($data['rows'] as $row) {
                fputcsv($out, array_map(fn ($c) => $row[$c['key']] ?? '', $data['columns']));
            }
            fclose($out);
        }, "agri-{$report}-{$from}-{$to}.csv", ['Content-Type' => 'text/csv']);
    }

    private function build(string $report, ?int $farmId, string $from, string $to): array
    {
        $f = fn ($q) => $q->when($farmId, fn ($w) => $w->where('farm_id', $farmId));
        $col = fn (string $key, string $title) => ['key' => $key, 'title' => $title];

        return match ($report) {
            'yield' => [
                'columns' => [$col('farm', 'Farm'), $col('plan', 'Plan'), $col('crop', 'Crop'), $col('field', 'Field'), $col('hectares', 'Hectares'),
                    $col('harvested', 'Harvested'), $col('unit', 'Unit'), $col('per_ha', 'Yield / ha'), $col('expected_per_ha', 'Plan / ha'), $col('variance_pct', 'Variance %')],
                'rows' => CropPlan::query()->tap($f)->whereIn('status', [CropPlan::HARVESTING, CropPlan::COMPLETED])
                    ->where(fn ($q) => $q->whereBetween('planned_harvest_on', [$from, $to])->orWhereHas('harvests', fn ($h) => $h->whereBetween('harvested_on', [$from, $to])))
                    ->with(['farm', 'cropType', 'field'])->get()
                    ->map(fn ($p) => ['farm' => $p->farm->name, 'plan' => $p->code, 'crop' => $p->cropType?->name, 'field' => $p->field?->code] + $this->harvests->yieldFor($p))->values()->all(),
            ],
            'activities' => [
                'columns' => [$col('date', 'Completed'), $col('farm', 'Farm'), $col('field', 'Field'), $col('plan', 'Plan'), $col('type', 'Activity'),
                    $col('title', 'Description'), $col('area', 'Area (ha)'), $col('labour_hours', 'Labour h'), $col('machine_hours', 'Machine h'), $col('input_cost', 'Input cost')],
                'rows' => FieldActivity::query()->tap($f)->where('status', 'completed')->whereBetween('completed_at', [$from.' 00:00:00', $to.' 23:59:59'])
                    ->with(['farm', 'field', 'cropPlan'])->withSum(['inputs as input_cost' => fn ($q) => $q->where('status', 'issued')], 'total_cost')
                    ->orderBy('completed_at')->get()
                    ->map(fn ($a) => ['date' => $a->completed_at->toDateString(), 'farm' => $a->farm->name, 'field' => $a->field?->code, 'plan' => $a->cropPlan?->code,
                        'type' => str_replace('_', ' ', $a->activity_type), 'title' => $a->title, 'area' => self::num($a->area_covered),
                        'labour_hours' => self::num($a->labour_hours), 'machine_hours' => self::num($a->machine_hours), 'input_cost' => round((float) $a->input_cost, 2)])->all(),
            ],
            'chemicals' => [
                'columns' => [$col('date', 'Applied'), $col('farm', 'Farm'), $col('field', 'Field'), $col('plan', 'Crop plan'), $col('product', 'Product'),
                    $col('quantity', 'Quantity'), $col('uom', 'Unit'), $col('rate_per_ha', 'Rate / ha'), $col('withholding_days', 'Withholding days'),
                    $col('harvest_from', 'Harvest not before'), $col('operator', 'Recorded by')],
                'rows' => ActivityInput::query()->tap($f)->where('status', 'issued')->whereBetween('issued_at', [$from.' 00:00:00', $to.' 23:59:59'])
                    ->with(['activity.field', 'activity.cropPlan', 'activity.farm', 'stockItem'])->orderBy('issued_at')->get()
                    ->map(fn ($i) => ['date' => $i->activity?->completed_at?->toDateString(), 'farm' => $i->activity?->farm?->name, 'field' => $i->activity?->field?->code,
                        'plan' => $i->activity?->cropPlan?->code, 'product' => $i->stockItem?->name, 'quantity' => (float) $i->quantity, 'uom' => $i->uom,
                        'rate_per_ha' => self::num($i->rate_per_ha), 'withholding_days' => $i->withholding_days,
                        'harvest_from' => $i->withholding_days ? $i->activity?->completed_at?->copy()->addDays($i->withholding_days)->toDateString() : null,
                        'operator' => $i->issued_by])->all(),
            ],
            'herd' => [
                'columns' => [$col('farm', 'Farm'), $col('group', 'Group'), $col('name', 'Name'), $col('species', 'Species'), $col('purpose', 'Purpose'),
                    $col('head', 'Head'), $col('camp', 'Camp')],
                'rows' => LivestockGroup::query()->tap($f)->where('status', 'active')->with(['farm', 'species', 'field'])->orderBy('code')->get()
                    ->map(fn ($g) => ['farm' => $g->farm->name, 'group' => $g->code, 'name' => $g->name, 'species' => $g->species?->name,
                        'purpose' => $g->purpose, 'head' => $g->currentHeadCount(), 'camp' => $g->field?->code])
                    ->push(['farm' => 'All', 'group' => '—', 'name' => 'Not in a group', 'species' => '', 'purpose' => '',
                        'head' => Animal::query()->tap($f)->where('status', 'active')->whereNull('group_id')->count(), 'camp' => ''])
                    ->filter(fn ($r) => $r['head'] > 0 || $r['group'] !== '—')->values()->all(),
            ],
            'treatments' => [
                'columns' => [$col('date', 'Date'), $col('farm', 'Farm'), $col('subject', 'Animal / group'), $col('type', 'Type'), $col('product', 'Product'),
                    $col('dose', 'Dose'), $col('head', 'Head'), $col('withdrawal_until', 'Withdrawal until'), $col('vet', 'Vet'), $col('cost', 'Cost')],
                'rows' => AnimalHealthRecord::query()->tap($f)->whereBetween('treated_on', [$from, $to])->with(['farm', 'animal', 'group'])->orderBy('treated_on')->get()
                    ->map(fn ($h) => ['date' => $h->treated_on->toDateString(), 'farm' => $h->farm->name, 'subject' => $h->animal?->tag_number ?? $h->group?->code,
                        'type' => $h->event_type, 'product' => $h->product_name, 'dose' => $h->dose, 'head' => $h->head_count,
                        'withdrawal_until' => $h->withdrawal_until?->toDateString(), 'vet' => $h->vet_name, 'cost' => self::num($h->cost)])->all(),
            ],
        };
    }

    private function period(Request $request): array
    {
        $ok = fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v);
        $from = $request->query('from');
        $to = $request->query('to');

        return [$ok($from) ? $from : today()->subYear()->toDateString(), $ok($to) ? $to : today()->toDateString()];
    }
}
