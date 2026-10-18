<?php

namespace App\Modules\Operations\DocumentManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Services\RecycleBin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Deleted documents, and putting them back.
 *
 * Restoring needs the restore permission, which is the same one that brings an archived document
 * back. Emptying the bin needs purge, because that is what emptying it does — the two are
 * deliberately not the same person's decision.
 */
class RecycleBinController extends Controller
{
    public function __construct(private readonly RecycleBin $bin)
    {
    }

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Document::class);

        $user = $request->user();

        return Inertia::render('Modules/DocumentManagement/Pages/RecycleBin', [
            'documents' => $this->bin->contents($user)->paginate(30)->through(fn (Document $d) => [
                'uuid' => $d->uuid,
                'title' => $d->title,
                'category' => $d->category?->name,
                'folder' => $d->folder?->name,
                'deleted_at' => $d->deleted_at?->toDayDateTimeString(),
                'deleted_ago' => $d->deleted_at?->diffForHumans(),
                'deleted_by' => $this->userName($d->deleted_by),
                'reason' => $d->delete_reason,
                // Said per row, because "how long have I got" is the question somebody opens this
                // page with.
                'purgeable_from' => $this->bin->purgeableFrom($d)?->toFormattedDateString(),
                'held' => (bool) $d->is_legally_sensitive,
            ]),
            'summary' => $this->bin->summary($user),
            'can' => [
                'restore' => $request->user()?->can('document.restore') ?? false,
                'purge' => $request->user()?->can('document.purge') ?? false,
            ],
        ]);
    }

    public function restore(Request $request, string $uuid): RedirectResponse
    {
        $document = Document::onlyTrashed()->where('uuid', $uuid)->firstOrFail();

        $this->authorize('restore', $document);

        $result = $this->bin->restore($document);

        return $result['ok']
            ? back()->with('success', __('document_management.recycle.restored'))
            : back()->with('error', $result['error']);
    }

    /** Permanent removal of one document, from the bin rather than from its own page. */
    public function purge(Request $request, string $uuid): RedirectResponse
    {
        $document = Document::onlyTrashed()->where('uuid', $uuid)->firstOrFail();

        $this->authorize('purge', $document);

        $result = app(\App\Modules\Operations\DocumentManagement\Services\DocumentService::class)->purge($document);

        return $result['ok']
            ? back()->with('success', __('document_management.recycle.purged'))
            : back()->with('error', $result['error']);
    }

    /**
     * Empties the bin.
     *
     * Only what is past its window unless somebody explicitly asks for everything — and never
     * anything under legal hold, whatever its age. The bin is exactly where somebody would look to
     * get around a hold.
     */
    public function empty(Request $request): RedirectResponse
    {
        $this->authorize('purge', Document::class);

        $counts = $this->bin->empty($request->boolean('everything'));

        return back()->with('success', __('document_management.recycle.emptied', [
            'purged' => $counts['purged'],
            'held' => $counts['held'],
        ]));
    }

    private function userName(?int $id): ?string
    {
        if (! $id) {
            return null;
        }

        $user = \App\Core\User\Models\User::withoutGlobalScopes()->find($id);
        $person = $user?->person;

        return $person ? trim($person->first_name . ' ' . $person->surname) : $user?->email;
    }
}
