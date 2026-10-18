<?php

namespace App\Modules\Operations\DocumentManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentEvent;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The audit trail across the whole repository.
 *
 * The Governance hub has had a card pointing here since the module was built, and behind it was the
 * ordinary document listing — so the one screen that answers "who did what to our documents" showed
 * a list of documents instead.
 *
 * Two things shape what it shows. It is scoped through the same visibility rule as every listing,
 * so an audit entry never discloses a document somebody may not know exists — a line reading
 * "Sarah downloaded Disciplinary hearing — J. Dlamini" is the disclosure the confidentiality flag
 * was set to prevent. And it is read-only for everybody: the events table has no update or delete
 * path anywhere in the module, which is what makes it worth consulting.
 */
class DocumentActivityController extends Controller
{
    /** How far back the unfiltered view reaches, so the first page is not a scan of the table. */
    private const DEFAULT_DAYS = 30;

    public function index(Request $request): Response
    {
        $this->authorize('viewActivity', Document::class);

        $user = $request->user();

        $events = DocumentEvent::query()
            ->with(['document:id,uuid,title', 'folder:id,uuid,name', 'user'])
            ->where(function ($q) use ($user) {
                /*
                 * Only events on documents this person may see. Asked as a subquery against the
                 * same scope the library uses, so the two cannot drift — an audit trail that shows
                 * more than the listing is a way of reading titles somebody was refused.
                 */
                $q->whereIn('document_id', Document::query()->visibleTo($user)->select('id'));

                /*
                 * And what happened to the folders themselves, which is where a permission change
                 * appears. Restricted to folders the person can open, for the same reason: the name
                 * of a confidential folder is a disclosure, and so is the fact that somebody was
                 * granted access to it.
                 */
                $closed = app(\App\Modules\Operations\DocumentManagement\Services\FolderAccess::class)
                    ->closedFolderIds($user);

                $q->orWhere(function ($folders) use ($closed) {
                    $folders->whereNotNull('folder_id');

                    if ($closed !== []) {
                        $folders->whereNotIn('folder_id', $closed);
                    }
                });
            })
            ->when($request->query('action'), fn ($q, $action) => $q->where('action', $action))
            ->when($request->query('document'), fn ($q, $uuid) => $q->whereIn(
                'document_id',
                Document::query()->where('uuid', $uuid)->select('id'),
            ))
            ->when($request->query('from'), fn ($q, $from) => $q->where('created_at', '>=', $from))
            ->when($request->query('to'), fn ($q, $to) => $q->where('created_at', '<=', $to . ' 23:59:59'))
            ->when(
                ! $request->hasAny(['action', 'document', 'from', 'to']),
                fn ($q) => $q->where('created_at', '>=', now()->subDays(self::DEFAULT_DAYS)),
            )
            ->latest('created_at')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (DocumentEvent $event) => [
                'id' => $event->id,
                'action' => $event->action,
                'at' => $event->created_at?->toDayDateTimeString(),
                'user' => $this->personName($event->user),
                'ip' => $event->ip,
                'detail' => $event->detail,
                'document' => $event->document ? [
                    'uuid' => $event->document->uuid,
                    'title' => $event->document->title,
                ] : null,
                'folder' => $event->folder ? [
                    'uuid' => $event->folder->uuid,
                    'name' => $event->folder->name,
                ] : null,
            ]);

        return Inertia::render('Modules/DocumentManagement/Pages/Activity', [
            'events' => $events,
            'filters' => $request->only(['action', 'document', 'from', 'to']),
            // Offered as a list so somebody can find "who deleted things" without knowing that the
            // event is called 'purged' internally.
            'actions' => $this->actions(),
            'default_days' => self::DEFAULT_DAYS,
        ]);
    }

    /** Every action the module records, in a stable order. */
    private function actions(): array
    {
        $reflection = new \ReflectionClass(DocumentEvent::class);

        return collect($reflection->getConstants())
            ->filter(fn ($value) => is_string($value))
            ->values()
            ->sort()
            ->values()
            ->all();
    }

    private function personName(?object $user): ?string
    {
        if (! $user) {
            // A system action, or somebody since removed. Both are better said than left blank.
            return null;
        }

        $person = $user->person ?? null;

        return $person ? trim($person->first_name . ' ' . $person->surname) : $user->email;
    }
}
