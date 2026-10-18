<?php

namespace App\Modules\Operations\ProjectManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\ProjectManagement\Models\Announcement;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Services\AnnouncementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Notices to a project team (EPIC-031).
 *
 * Reading is open to anyone who can see projects — a notice nobody may read is not a notice. Posting
 * requires being able to change something: it reaches everyone's phone, and that is not a privilege
 * that comes with read access.
 */
class AnnouncementController extends Controller
{
    public function __construct(private readonly AnnouncementService $announcements)
    {
    }

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Project::class);

        $project = $request->filled('project')
            ? Project::where('uuid', $request->string('project'))->first()
            : null;

        return Inertia::render('Modules/ProjectManagement/Announcements/Index', [
            'announcements' => $this->announcements->forUser($request->user(), $project),
            'severities'    => Announcement::SEVERITIES,
            // Only projects this person can actually post to, so the picker never offers one whose
            // submission would be refused.
            'projects'      => Project::query()->visibleTo($request->user())
                ->orderBy('name')->limit(200)->get(['uuid', 'code', 'name'])
                ->map(fn (Project $p) => ['id' => $p->uuid, 'label' => $p->code . ' · ' . $p->name])
                ->all(),
            'filter'        => ['project' => $project?->uuid],
            'can'           => ['post' => $request->user()->can('create', Project::class)],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Project::class);

        $data = $request->validate([
            'project_id'   => ['nullable', 'uuid'],
            'title'        => ['required', 'string', 'max:190'],
            'body'         => ['required', 'string', 'max:5000'],
            'severity'     => ['required', Rule::in(Announcement::SEVERITIES)],
            'is_pinned'    => ['nullable', 'boolean'],
            'requires_ack' => ['nullable', 'boolean'],
            'expires_at'   => ['nullable', 'date', 'after:now'],
        ]);

        $project = ! empty($data['project_id'])
            ? Project::where('uuid', $data['project_id'])->firstOrFail()
            : null;

        // A notice to one project is authorised against that project; a portfolio-wide one against
        // the module. Otherwise anyone able to post on their own job could text the whole company.
        if ($project) {
            $this->authorize('update', $project);
        } else {
            $this->authorize('manageSettings', Project::class);
        }

        $this->announcements->post($project, $data);

        return back()->with('success', __('project_management.flash.announcement_posted'));
    }

    /** Seen, and where asked, accepted. */
    public function acknowledge(Request $request, string $uuid): RedirectResponse
    {
        $this->authorize('viewAny', Project::class);

        $announcement = Announcement::where('uuid', $uuid)->firstOrFail();

        $this->announcements->markRead(
            $announcement,
            (int) $request->user()->id,
            (bool) $request->boolean('acknowledge', true),
        );

        return back()->with('success', __('project_management.flash.announcement_acknowledged'));
    }

    public function destroy(string $uuid): RedirectResponse
    {
        $announcement = Announcement::where('uuid', $uuid)->firstOrFail();

        $announcement->project_id
            ? $this->authorize('update', $announcement->project)
            : $this->authorize('manageSettings', Project::class);

        // Soft delete: who was told what, and when, survives the notice being taken down.
        $announcement->delete();

        return back()->with('success', __('project_management.flash.announcement_removed'));
    }
}
