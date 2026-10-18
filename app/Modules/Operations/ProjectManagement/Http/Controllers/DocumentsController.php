<?php

namespace App\Modules\Operations\ProjectManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\ProjectManagement\Contracts\Providers\DocumentStore;
use App\Modules\Operations\ProjectManagement\Models\ExternalRef;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\ProjectMember;
use App\Modules\Operations\ProjectManagement\Services\DocumentVersionService;
use App\Modules\Operations\ProjectManagement\Services\ModuleRegistry;
use App\Modules\Operations\ProjectManagement\Services\ProviderRegistry;
use App\Modules\Operations\ProjectManagement\Support\Providers\ProvidedRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A project's documents (EPIC-050).
 *
 * PM never holds the bytes. Files go to whichever DocumentStore is installed — its own private disk
 * when the Documents module is not licensed, that module when it is — and this page lists whatever
 * the store reports. Swapping stores therefore changes where documents live without changing
 * anything a user sees.
 */
class DocumentsController extends Controller
{
    /** Larger than a task attachment: drawings and specifications are the point of this screen. */
    public const MAX_BYTES = 50 * 1024 * 1024;

    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly ModuleRegistry $modules,
        private readonly DocumentVersionService $versions,
    ) {
    }

    public function index(Request $request, Project $project): Response
    {
        $this->authorize('view', $project);

        $documents = $this->documents()->forProject($project->uuid);
        $versioned = $this->versions->supportsVersioning();
        $userId = (int) $request->user()->id;

        return Inertia::render('Modules/ProjectManagement/Projects/Documents', [
            'project'   => ['id' => $project->uuid, 'code' => $project->code, 'name' => $project->name],
            'documents' => array_map(function (ProvidedRecord $d) use ($versioned, $userId) {
                $row = [
                    'id'     => $d->id,
                    'name'   => $d->label,
                    'source' => $d->source,
                    'size'   => $d->meta['size'] ?? null,
                    'mime'   => $d->meta['mime'] ?? null,
                ];

                if (! $versioned) {
                    return $row;
                }

                // The signature panel is per-document and cheap; the revision history is not, so it
                // is fetched on demand when somebody opens it rather than for every row.
                return $row + [
                    'version'    => $d->meta['version'] ?? 1,
                    'revision'   => $d->meta['revision'] ?? null,
                    'signatures' => $this->versions->signatures($d->id),
                    'can_sign'   => $this->versions->awaitingSignatureFrom($d->id, $userId),
                ];
            }, $documents),
            // Says plainly where files are going, so nobody is surprised later about what the
            // Documents module would have given them.
            'store'     => $this->modules->has('documents') ? 'documents_module' : 'database',
            'versioned' => $versioned,
            // Who can be put on a signing round. Project members rather than every user on the
            // tenant: a payment certificate is signed by the people on that job.
            'signers'   => $versioned ? $this->signerOptions($project) : [],
            'maxBytes'  => self::MAX_BYTES,
            'can'       => ['manage' => $request->user()->can('update', $project)],
        ]);
    }

    /** Every revision of one document, newest first. */
    public function history(Project $project, string $documentId): JsonResponse
    {
        $this->authorize('view', $project);

        abort_unless($this->belongsToProject($project, $documentId), 404);

        return response()->json(['versions' => $this->versions->history($documentId)]);
    }

    /**
     * Upload a new revision.
     *
     * Separate from `store()` because the two mean different things: this one keeps the document's
     * identity and supersedes what was there, where `store()` adds a document that did not exist.
     */
    public function storeVersion(Request $request, Project $project, string $documentId): RedirectResponse
    {
        $this->authorize('update', $project);

        abort_unless($this->belongsToProject($project, $documentId), 404);

        $data = $request->validate([
            'file'     => ['required', 'file', 'max:' . (self::MAX_BYTES / 1024)],
            'revision' => ['nullable', 'string', 'max:20'],
        ]);

        try {
            $this->versions->newVersion(
                $project,
                $documentId,
                $request->file('file'),
                $data['revision'] ?? null,
                (int) $request->user()->id,
            );
        } catch (\InvalidArgumentException|RuntimeException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        return back()->with('success', __('project_management.flash.revision_uploaded'));
    }

    public function sendForSignature(Request $request, Project $project, string $documentId): RedirectResponse
    {
        $this->authorize('update', $project);

        abort_unless($this->belongsToProject($project, $documentId), 404);

        $data = $request->validate([
            'signers'           => ['required', 'array', 'min:1', 'max:10'],
            'signers.*.name'    => ['required', 'string', 'max:190'],
            'signers.*.email'   => ['nullable', 'email', 'max:190'],
            'signers.*.user_id' => ['nullable', 'integer'],
            'signers.*.role'    => ['nullable', 'string', 'max:60'],
        ]);

        try {
            $this->versions->sendForSignature($project, $documentId, $data['signers']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['signers' => $e->getMessage()]);
        }

        return back()->with('success', __('project_management.flash.sent_for_signature'));
    }

    /**
     * Sign.
     *
     * Only `view` is required, not `update`: a signatory is frequently somebody who may read the
     * project and sign for it without being able to change anything on it, and the service checks
     * that this user is actually on the envelope.
     */
    public function sign(Request $request, Project $project, string $documentId): RedirectResponse
    {
        $this->authorize('view', $project);

        abort_unless($this->belongsToProject($project, $documentId), 404);

        $data = $request->validate(['typed_name' => ['required', 'string', 'max:190']]);

        try {
            $this->versions->sign(
                $project,
                $documentId,
                (int) $request->user()->id,
                $data['typed_name'],
                $request->ip(),
                (string) $request->userAgent(),
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['typed_name' => $e->getMessage()]);
        }

        return back()->with('success', __('project_management.flash.document_signed'));
    }

    public function declineSignature(Request $request, Project $project, string $documentId): RedirectResponse
    {
        $this->authorize('view', $project);

        abort_unless($this->belongsToProject($project, $documentId), 404);

        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        try {
            $this->versions->decline($documentId, (int) $request->user()->id, $data['reason']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('success', __('project_management.flash.signature_declined'));
    }

    /** @return list<array<string,mixed>> */
    private function signerOptions(Project $project): array
    {
        return ProjectMember::where('project_id', $project->id)
            ->active()
            ->orderBy('display_name')
            ->get(['user_id', 'display_name', 'email', 'role_key'])
            ->map(fn (ProjectMember $m) => [
                'user_id' => $m->user_id,
                'name'    => $m->display_name,
                'email'   => $m->email,
                'role'    => $m->role_key,
            ])
            ->all();
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $request->validate(['file' => ['required', 'file', 'max:' . (self::MAX_BYTES / 1024)]]);

        try {
            // The store decides what it will accept and how it stores it. Locally that is the
            // platform's FileStorage trait — same validation, same compression and same column
            // shape as every other upload on this system.
            $this->documents()->put($project->uuid, $request->file('file'), [
                'uploaded_by' => $request->user()->id,
            ]);
        } catch (\InvalidArgumentException $e) {
            // Too large, or a type this store refuses. A field error, not a 500 — the person
            // chose the wrong file and can choose another.
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        return back()->with('success', __('project_management.flash.document_uploaded'));
    }

    /**
     * Serve the document.
     *
     * Streamed from the store when the store holds the bytes — which is the local store, where
     * they are rows in a tenant-scoped table. Every read therefore passes this policy check, and
     * there is no URL in existence that would serve the file to someone who did not.
     *
     * A store that keeps its bytes elsewhere hands back a time-limited link instead, and we
     * redirect to it.
     */
    public function show(Project $project, string $documentId): RedirectResponse|StreamedResponse
    {
        $this->authorize('view', $project);

        // Bound to this project: a document uuid from another job must not resolve just because
        // the caller can see some project.
        abort_unless($this->belongsToProject($project, $documentId), 404);

        if ($contents = $this->documents()->contents($documentId)) {
            return response()->streamDownload(
                fn () => print $contents->bytes,
                $contents->filename,
                $contents->headers(),
                'inline',
            );
        }

        $url = $this->documents()->url($documentId);

        abort_if($url === null, 404);

        return redirect()->away($url);
    }

    public function destroy(Project $project, string $documentId): RedirectResponse
    {
        $this->authorize('update', $project);

        abort_unless($this->belongsToProject($project, $documentId), 404);

        // The store decides what "delete" means for its own bytes: the local one removes them,
        // because it owns them; an integrated one removes only the link, because the document is
        // another module's record and deleting it from here would be a guess.
        $this->documents()->delete($documentId);

        return back()->with('success', __('project_management.flash.document_removed'));
    }

    private function belongsToProject(Project $project, string $documentId): bool
    {
        return ExternalRef::where('uuid', $documentId)
            ->where('source_entity', 'document')
            ->where('snapshot->project_id', $project->uuid)
            ->exists();
    }

    private function documents(): DocumentStore
    {
        return $this->providers->for(DocumentStore::class);
    }
}
