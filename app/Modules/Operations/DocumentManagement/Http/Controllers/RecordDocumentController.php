<?php

namespace App\Modules\Operations\DocumentManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Services\DocumentService;
use App\Modules\Operations\DocumentManagement\Support\DocumentRelations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Uploading a document from another module's page.
 *
 * The rest of the platform reaches the repository through here: an employee page, a vehicle, a
 * supplier. The record is named by relation and key rather than by model class, so a request cannot
 * nominate an arbitrary class to instantiate — the relation list is the allow-list, and anything
 * not on it is a 404 rather than an attempt.
 *
 * It lives inside the module's own route group, so module.active and module.tier apply exactly as
 * they do everywhere else. A company without Document Management on its subscription does not get a
 * second way in through Fleet.
 */
class RecordDocumentController extends Controller
{
    public function __construct(private readonly DocumentService $documents)
    {
    }

    public function store(Request $request, string $relation, string $key): RedirectResponse
    {
        $this->authorize('create', Document::class);

        $record = $this->resolve($relation, $key);

        $data = $request->validate([
            'file' => ['required', 'file'],
            'title' => ['nullable', 'string', 'max:240'],
            'category_id' => ['nullable', 'integer'],
            'document_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $result = $this->documents->store($request->file('file'), [
            'title' => $data['title'] ?? null,
            'description' => $data['description'] ?? null,
            'category_id' => $data['category_id'] ?? null,
            'document_date' => $data['document_date'] ?? null,
            'expiry_date' => $data['expiry_date'] ?? null,
        ]);

        if ($result['document'] === null) {
            return back()->with('error', $result['error']);
        }

        // Linked in the same request as the upload. A document uploaded from an employee's page and
        // then not attached to them is a document nobody will ever find again.
        $record->attachDocument($result['document'], $relation);

        return back()->with('success', __('document_management.records.attached'));
    }

    /** Links a document that already exists. */
    public function attach(Request $request, string $relation, string $key): RedirectResponse
    {
        $this->authorize('viewAny', Document::class);

        $record = $this->resolve($relation, $key);

        $data = $request->validate(['document' => ['required', 'string']]);

        // Through the visibility scope: linking is not a way to reach a document somebody could not
        // otherwise open, and a uuid they should not know about answers as though it does not exist.
        $document = Document::query()
            ->visibleTo($request->user())
            ->where('uuid', $data['document'])
            ->first();

        if (! $document) {
            throw new NotFoundHttpException();
        }

        $record->attachDocument($document, $relation);

        return back()->with('success', __('document_management.records.attached'));
    }

    /** Removes the link. The document itself is untouched — it exists in its own right. */
    public function detach(Request $request, string $relation, string $key): RedirectResponse
    {
        $this->authorize('viewAny', Document::class);

        $record = $this->resolve($relation, $key);

        $data = $request->validate(['document' => ['required', 'string']]);

        $document = Document::query()
            ->visibleTo($request->user())
            ->where('uuid', $data['document'])
            ->first();

        if ($document) {
            $record->detachDocument($document, $relation);
        }

        return back()->with('success', __('document_management.records.detached'));
    }

    /**
     * Finds the record a relation names.
     *
     * The relation list is the allow-list. A request naming something not on it gets a 404, and one
     * naming a model that cannot carry documents gets the same — there is nothing to tell an
     * attacker apart from a typo, and neither deserves a different answer.
     */
    private function resolve(string $relation, string $key): object
    {
        $definition = DocumentRelations::all()[$relation] ?? null;

        if ($definition === null || ! isset($definition['model'])) {
            throw new NotFoundHttpException();
        }

        $model = $definition['model'];

        /*
         * A uuid where the record has one, the primary key otherwise.
         *
         * The platform's rule is that an internal id never appears in a URL. Records addressed here
         * were all reached by id because that is what the panel was handed, so both are accepted:
         * hosts that already pass an id keep working, and a host that passes its uuid is resolved
         * by that. A uuid is only looked up as one on a model that has the column -- never guessed.
         */
        $query = $model::query();

        $record = (\Illuminate\Support\Str::isUuid($key)
                && \Illuminate\Support\Facades\Schema::hasColumn((new $model)->getTable(), 'uuid'))
            ? $query->where('uuid', $key)->first()
            : $query->whereKey($key)->first();

        if (! $record || ! method_exists($record, 'attachDocument')) {
            throw new NotFoundHttpException();
        }

        return $record;
    }
}
