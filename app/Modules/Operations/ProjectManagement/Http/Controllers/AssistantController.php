<?php

namespace App\Modules\Operations\ProjectManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\ProjectManagement\Models\Meeting;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Services\PmAssistantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The project assistant (§25).
 *
 * The insights are deterministic and always available; only the drafting needs a model provider.
 * That split is why this page is useful on a workspace with no AI configured at all.
 */
class AssistantController extends Controller
{
    public function __construct(private readonly PmAssistantService $assistant)
    {
    }

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Project::class);

        return Inertia::render('Modules/ProjectManagement/Insights/Assistant', [
            'insights' => $this->assistant->insights(),
            'canWrite' => $this->assistant->canWrite(),
            'projects' => Project::query()->visibleTo($request->user())->orderBy('name')->limit(200)
                ->get(['uuid', 'code', 'name'])
                ->map(fn (Project $p) => ['id' => $p->uuid, 'label' => $p->code . ' · ' . $p->name])->all(),
        ]);
    }

    /**
     * Draft a status report.
     *
     * Returned as JSON rather than flashed, because it is a draft somebody edits before sending —
     * putting it through a redirect would lose it on the first mistake.
     */
    public function statusReport(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $draft = $this->assistant->draftStatusReport($project);

        return response()->json([
            'draft'     => $draft,
            // Said plainly rather than returning an empty box: a tenant with no provider should
            // know why nothing came back.
            'available' => $draft !== null,
        ]);
    }

    public function minutes(Request $request, string $uuid): JsonResponse
    {
        $this->authorize('create', Project::class);

        $meeting = Meeting::where('uuid', $uuid)->firstOrFail();

        $data = $request->validate(['notes' => ['required', 'string', 'max:20000']]);

        $draft = $this->assistant->draftMinutes($data['notes'], (string) $meeting->agenda);

        return response()->json(['draft' => $draft, 'available' => $draft !== null]);
    }
}
