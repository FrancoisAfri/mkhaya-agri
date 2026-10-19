<?php

namespace App\Modules\Agri\Http\Controllers;

use App\Modules\Agri\Models\FarmTask;
use App\Modules\Agri\Models\FieldActivity;
use App\Modules\Agri\Support\RoleCatalog as R;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Module K: the phone screen for people in the field — today's tasks and activities assigned to
 * me, with one-tap start / submit / complete and a quick scouting report. Uses the same endpoints
 * and rules as the full screens; it only lays them out for a small screen.
 */
class MyWorkController extends AgriController
{
    public function index(Request $request): Response
    {
        $this->allow($request, R::VIEW_TASKS, R::RECORD_ACTIVITIES);
        $user = $request->user();

        return Inertia::render('Modules/Agri/MyWork', [
            'tasks' => FarmTask::query()->where('assigned_to', $user->id)->whereIn('status', [...FarmTask::PENDING, FarmTask::SUBMITTED])
                ->with(['farm', 'field'])->orderByRaw('due_on IS NULL')->orderBy('due_on')->get()
                ->map(fn ($t) => ['id' => $t->uuid, 'title' => $t->title, 'description' => $t->description, 'status' => $t->status,
                    'priority' => $t->priority, 'due_on' => $t->due_on?->toDateString(), 'overdue' => $t->isOverdue(),
                    'farm' => $t->farm?->name, 'field' => $t->field?->code, 'review_note' => $t->review_note]),
            'activities' => FieldActivity::query()->where('assigned_to', $user->id)->whereIn('status', ['planned', 'in_progress'])
                ->with(['farm', 'field', 'cropPlan', 'inputs.stockItem'])->orderBy('planned_on')->get()
                ->map(fn ($a) => ['id' => $a->uuid, 'title' => $a->title, 'type' => $a->activity_type, 'status' => $a->status,
                    'planned_on' => $a->planned_on->toDateString(), 'farm' => $a->farm?->name, 'field' => $a->field?->code,
                    'plan' => $a->cropPlan?->code, 'inputs' => $a->inputs->map(fn ($i) => rtrim(rtrim(number_format((float) $i->quantity, 3, '.', ' '), '0'), '.')." {$i->uom} {$i->stockItem?->name}")]),
            'farms' => $this->farmOptions(true),
            'can' => ['observe' => $user->can(R::RECORD_ACTIVITIES)],
        ]);
    }
}
