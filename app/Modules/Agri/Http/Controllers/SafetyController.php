<?php

namespace App\Modules\Agri\Http\Controllers;

use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmWorkOrder as WO;
use App\Modules\Agri\Services\FarmSafety;
use App\Modules\Agri\Support\RoleCatalog as R;
use App\Modules\Operations\Sheq\Services\InspectionGates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 2 §12.2: safety on the farm is SHEQ's. Anyone who can open a farm reports a danger here and
 * it goes into SHEQ's hazard and incident registers; a job gated by a SHEQ inspection opens SHEQ's
 * own inspection form for that job. Compliance registers, schemes and checklists are SHEQ screens.
 */
class SafetyController extends AgriController
{
    public function __construct(private readonly FarmSafety $safety) {}

    public function report(Request $request): RedirectResponse
    {
        $this->allow($request, R::VIEW_FARMS);
        $data = $request->validate(['farm_id' => ['required', 'string'], 'kind' => ['required', Rule::in(FarmSafety::KINDS)], 'description' => ['required', 'string', 'max:2000'],
            'occurred_at' => ['nullable', 'date', 'before_or_equal:now'], 'location' => ['nullable', 'string', 'max:200'], 'activity' => ['nullable', 'string', 'max:200'],
            'immediate_action' => ['nullable', 'string', 'max:1000'], 'severity' => ['nullable', 'integer', 'between:1,5'], 'outcome' => ['nullable', 'string', 'max:30'],
            'treatment_level' => ['nullable', 'string', 'max:30'], 'machine_id' => ['nullable', 'string'], 'substance' => ['nullable', 'string', 'max:160'],
            'quantity' => ['nullable', 'numeric', 'gte:0'], 'unit' => ['nullable', 'string', 'max:10'], 'receiving_environment' => ['nullable', 'string', 'max:30'],
            'anonymous' => ['boolean'], 'client_id' => ['nullable', 'string', 'max:64'], 'latitude' => ['nullable', 'numeric'], 'longitude' => ['nullable', 'numeric']]);
        $farm = $this->farm($data['farm_id']);   // anyone who can open the farm may report a danger on it
        $r = $this->safety->report($farm, $data, $request->user());

        return back()->with('success', 'Reported as '.$r['reference'].'.'.($r['also'] ? ' '.implode('. ', $r['also']).'.' : '').' SHEQ takes it from here.');
    }

    /** Open SHEQ's form for a job's gating inspection. */
    public function inspect(Request $request, WO $order, string $template): RedirectResponse
    {
        $this->allow($request, R::RECORD_ACTIVITIES);
        abort_unless(collect($this->safety->requiredFor($order))->contains('code', $template), 404);
        $farm = Farm::query()->findOrFail($order->farm_id);
        $schedule = app(InspectionGates::class)->scheduleFor((int) $order->company_id, $template, InspectionGates::SUBJECT_WORK_ORDER, (int) $order->id,
            $this->safety->site($farm, $request->user()));

        return redirect("/sheq/inspections/{$schedule}/perform");
    }
}
