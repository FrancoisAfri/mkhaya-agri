<?php

namespace App\Modules\Operations\ProjectManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\ProjectManagement\Models\Meeting;
use App\Modules\Operations\ProjectManagement\Models\MeetingAttendee;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\ProjectMember;
use App\Modules\Operations\ProjectManagement\Models\Task;
use App\Modules\Operations\ProjectManagement\Services\TaskService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Meetings: agenda, minutes, attendance and actions (§18).
 *
 * Actions are created as **tasks on the project**, not rows in a meetings table. An action item
 * that lives only in minutes is one nobody works — it does not appear on anybody's list, it is not
 * counted as overdue, and it is invisible to every report. Making them tasks means the action from
 * Tuesday's site meeting sits in the same place as everything else somebody has to do.
 */
class MeetingController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Project::class);

        $project = $request->filled('project')
            ? Project::where('uuid', $request->string('project'))->first()
            : null;

        $meetings = Meeting::query()
            ->when($project, fn ($q) => $q->where('project_id', $project->id))
            ->with(['attendees', 'project:id,uuid,code,name'])
            ->withCount(['actions', 'actions as open_actions_count' => fn ($q) => $q->whereNotIn('status', ['done', 'cancelled'])])
            ->orderByDesc('starts_at')
            ->limit(200)
            ->get();

        return Inertia::render('Modules/ProjectManagement/Meetings/Index', [
            'meetings' => $meetings->map(fn (Meeting $m) => [
                'id'        => $m->uuid,
                'title'     => $m->title,
                'kind'      => $m->kind,
                'starts_at' => $m->starts_at->toIso8601String(),
                'duration'  => $m->duration_minutes,
                'location'  => $m->location,
                'url'       => $m->meeting_url,
                'agenda'    => $m->agenda,
                'minutes'   => $m->minutes,
                'status'    => $m->status,
                // Held, minutes never circulated — the state where decisions quietly evaporate.
                'minutes_outstanding' => $m->minutesOutstanding(),
                'project'   => $m->project ? ['id' => $m->project->uuid, 'code' => $m->project->code] : null,
                'attendees' => $m->attendees->map(fn (MeetingAttendee $a) => [
                    'id'   => $a->uuid,
                    'name' => $a->name,
                    'organisation' => $a->organisation,
                    'attendance'   => $a->attendance,
                ])->all(),
                'actions'      => $m->actions_count,
                'open_actions' => $m->open_actions_count,
            ])->all(),
            'projects' => Project::query()->visibleTo($request->user())->orderBy('name')->limit(200)
                ->get(['uuid', 'code', 'name'])
                ->map(fn (Project $p) => ['id' => $p->uuid, 'label' => $p->code . ' · ' . $p->name])->all(),
            'members' => $project
                ? ProjectMember::where('project_id', $project->id)->active()->orderBy('display_name')
                    ->get(['user_id', 'display_name', 'email'])
                    ->map(fn (ProjectMember $m) => ['id' => $m->user_id, 'name' => $m->display_name])->all()
                : [],
            'kinds'  => Meeting::KINDS,
            'filter' => ['project' => $project?->uuid],
            'can'    => ['manage' => $request->user()->can('create', Project::class)],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Project::class);

        $data = $this->rules($request);

        $project = ! empty($data['project_id'])
            ? Project::where('uuid', $data['project_id'])->firstOrFail()
            : null;

        if ($project) {
            $this->authorize('view', $project);
        }

        Meeting::create([
            ...collect($data)->except('project_id')->all(),
            'project_id' => $project?->id,
            'created_by' => Auth::id(),
            'chaired_by' => Auth::id(),
        ]);

        return back()->with('success', __('project_management.flash.meeting_scheduled'));
    }

    public function update(Request $request, string $uuid): RedirectResponse
    {
        $this->authorize('create', Project::class);

        $meeting = Meeting::where('uuid', $uuid)->firstOrFail();

        $meeting->update(collect($this->rules($request))->except('project_id')->all());

        return back()->with('success', __('project_management.flash.meeting_updated'));
    }

    /**
     * Record what happened: minutes, who was actually there, and what has to be done.
     *
     * Attendance is part of the same submission as the minutes on purpose. Filing them separately
     * is how minutes end up with nobody recorded as present, and "it was agreed at the site
     * meeting" stops being an argument.
     */
    public function recordMinutes(Request $request, string $uuid, TaskService $tasks): RedirectResponse
    {
        $this->authorize('create', Project::class);

        $meeting = Meeting::where('uuid', $uuid)->firstOrFail();

        $data = $request->validate([
            'minutes'                => ['required', 'string', 'max:20000'],
            'circulate'              => ['nullable', 'boolean'],
            'attendees'              => ['nullable', 'array', 'max:100'],
            'attendees.*.name'       => ['required', 'string', 'max:190'],
            'attendees.*.organisation' => ['nullable', 'string', 'max:190'],
            'attendees.*.attendance' => ['nullable', Rule::in(MeetingAttendee::ATTENDANCE)],
            'attendees.*.user_id'    => ['nullable', 'integer'],
            'actions'                => ['nullable', 'array', 'max:50'],
            'actions.*.name'         => ['required', 'string', 'max:190'],
            'actions.*.assigned_to'  => ['nullable', 'integer'],
            'actions.*.due_date'     => ['nullable', 'date'],
        ]);

        DB::transaction(function () use ($meeting, $data, $tasks) {
            $meeting->update([
                'minutes' => $data['minutes'],
                'status'  => 'held',
                'minutes_circulated_at' => ($data['circulate'] ?? false) ? now() : $meeting->minutes_circulated_at,
            ]);

            foreach ($data['attendees'] ?? [] as $a) {
                MeetingAttendee::updateOrCreate(
                    ['meeting_id' => $meeting->id, 'name' => $a['name']],
                    [
                        'organisation' => $a['organisation'] ?? null,
                        'attendance'   => $a['attendance'] ?? 'present',
                        'user_id'      => $a['user_id'] ?? null,
                    ],
                );
            }

            // Actions become real tasks on the project. Without a project there is nowhere to put
            // them, and a portfolio-wide meeting's actions are recorded in the minutes text.
            if ($meeting->project) {
                foreach ($data['actions'] ?? [] as $action) {
                    $task = $tasks->create($meeting->project, [
                        'name'        => $action['name'],
                        'description' => __('project_management.meetings.action_from', ['title' => $meeting->title]),
                        'planned_end' => $action['due_date'] ?? null,
                        'priority'    => 'high',
                        'created_by'  => Auth::id(),
                    ]);

                    $task->update(['meeting_id' => $meeting->id]);
                }
            }
        });

        return back()->with('success', __('project_management.flash.minutes_recorded'));
    }

    public function destroy(string $uuid): RedirectResponse
    {
        $this->authorize('create', Project::class);

        Meeting::where('uuid', $uuid)->firstOrFail()->delete();

        return back()->with('success', __('project_management.flash.meeting_removed'));
    }

    /** @return array<string,mixed> */
    private function rules(Request $request): array
    {
        return $request->validate([
            'project_id'       => ['nullable', 'uuid'],
            'title'            => ['required', 'string', 'max:190'],
            'kind'             => ['required', Rule::in(Meeting::KINDS)],
            'starts_at'        => ['required', 'date'],
            'duration_minutes' => ['nullable', 'integer', 'between:5,1440'],
            'location'         => ['nullable', 'string', 'max:190'],
            'meeting_url'      => ['nullable', 'url', 'max:500'],
            'agenda'           => ['nullable', 'string', 'max:10000'],
        ]);
    }
}
