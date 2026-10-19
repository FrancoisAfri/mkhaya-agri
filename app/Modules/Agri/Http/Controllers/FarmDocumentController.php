<?php

namespace App\Modules\Agri\Http\Controllers;

use App\Core\User\Trait\FileStorage;
use App\Modules\Agri\Models\Animal;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\FarmDocument;
use App\Modules\Agri\Models\FarmField;
use App\Modules\Agri\Models\FarmMachine;
use App\Modules\Agri\Models\LivestockGroup;
use App\Modules\Agri\Support\RoleCatalog as R;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Module J: a farm's compliance documents — title deeds, water use licences, pesticide and
 * operator certificates, brand registrations, movement permits, GLOBALG.A.P., insurance — with
 * their expiry dates. The file is kept in the database, like every document on the platform.
 */
class FarmDocumentController extends AgriController
{
    use FileStorage;

    private const SUBJECT_MODELS = [
        'field' => [FarmField::class, 'code'], 'crop_plan' => [CropPlan::class, 'code'],
        'livestock_group' => [LivestockGroup::class, 'code'], 'animal' => [Animal::class, 'tag_number'], 'machine' => [FarmMachine::class, 'code'],
    ];

    public function index(Request $request): Response
    {
        $this->allow($request, R::VIEW_DOCUMENTS);
        $farm = $this->farmFilter($request);
        $state = $request->query('state', 'all');

        $docs = FarmDocument::query()->when($farm, fn ($q) => $q->where('farm_id', $farm->id))->with('farm')
            ->where('status', $request->query('status', 'active'))
            ->orderByRaw('expires_on IS NULL')->orderBy('expires_on')->get()
            ->map(fn (FarmDocument $d) => [
                'id' => $d->uuid, 'title' => $d->title, 'document_type' => $d->document_type, 'reference' => $d->reference,
                'farm' => $d->farm?->name, 'farm_id' => $d->farm?->uuid, 'subject' => $this->subjectLabel($d),
                'issued_on' => $d->issued_on?->toDateString(), 'expires_on' => $d->expires_on?->toDateString(),
                'days_left' => $d->expires_on ? (int) today()->diffInDays($d->expires_on, false) : null,
                'state' => $d->expiryState(), 'file_name' => $d->file_name, 'file_size' => $d->file_size, 'status' => $d->status,
            ])
            ->when($state !== 'all', fn ($c) => $c->where('state', $state))->values();

        return Inertia::render('Modules/Agri/Documents/Index', [
            'documents' => $docs,
            'filters' => ['farm' => $farm?->uuid, 'state' => $state, 'status' => $request->query('status', 'active')],
            'options' => ['farms' => $this->farmOptions(true), 'types' => FarmDocument::TYPES, 'subjects' => FarmDocument::SUBJECTS],
            'can' => ['manage' => $request->user()->can(R::MANAGE_DOCUMENTS)],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->allow($request, R::MANAGE_DOCUMENTS);
        $data = $request->validate([
            'farm_id' => ['required', 'string'],
            'document_type' => ['required', Rule::in(FarmDocument::TYPES)],
            'title' => ['required', 'string', 'max:200'],
            'reference' => ['nullable', 'string', 'max:80'],
            'subject_type' => ['nullable', Rule::in(FarmDocument::SUBJECTS)],
            'subject_code' => ['nullable', 'required_with:subject_type', 'string', 'max:40'],
            'issued_on' => ['nullable', 'date', 'before_or_equal:today'],
            'expires_on' => ['nullable', 'date', 'after_or_equal:issued_on'],
            'remind_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'file' => ['nullable', 'file', 'max:10240'],
        ]);
        $farm = $this->workingFarm($request, $data['farm_id']);
        $subjectId = null;
        if (! empty($data['subject_type'])) {
            [$class, $col] = self::SUBJECT_MODELS[$data['subject_type']];
            $subjectId = $class::query()->where('farm_id', $farm->id)->whereRaw("lower({$col}) = ?", [mb_strtolower($data['subject_code'])])->value('id');
            if (! $subjectId) {
                throw ValidationException::withMessages(['subject_code' => 'Nothing with that code on this farm.']);
            }
        }
        $file = $request->hasFile('file') ? $this->saveFileToDatabase($request->file('file')) : null;

        FarmDocument::create([
            'company_id' => $farm->company_id, 'farm_id' => $farm->id, 'document_type' => $data['document_type'],
            'title' => $data['title'], 'reference' => $data['reference'] ?? null,
            'subject_type' => $data['subject_type'] ?? null, 'subject_id' => $subjectId,
            'issued_on' => $data['issued_on'] ?? null, 'expires_on' => $data['expires_on'] ?? null,
            'remind_days' => $data['remind_days'] ?? 30,
            'file_name' => $file['original_name'] ?? null, 'mime_type' => $file['mime'] ?? null,
            'file_size' => $file['size'] ?? null, 'data' => $file['data'] ?? null,
            'status' => 'active', 'uploaded_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Document filed.');
    }

    public function download(Request $request, FarmDocument $document)
    {
        $this->allow($request, R::VIEW_DOCUMENTS);
        abort_unless($document->data, 404, 'No file was attached to this record.');

        return $this->downloadResponse($document->data, $document->mime_type ?? 'application/octet-stream', $document->file_name ?? 'document');
    }

    public function update(Request $request, FarmDocument $document): RedirectResponse
    {
        $this->allow($request, R::MANAGE_DOCUMENTS);
        $this->assertWritable($request, $document->farm_id);
        $data = $request->validate([
            'status' => ['required', Rule::in(['active', 'superseded', 'archived'])],
            'expires_on' => ['nullable', 'date'],
            'remind_days' => ['nullable', 'integer', 'min:0', 'max:365'],
        ]);
        $document->update(array_filter($data, fn ($v) => $v !== null));

        return back()->with('success', 'Document updated.');
    }

    private function subjectLabel(FarmDocument $d): ?string
    {
        if (! $d->subject_type || ! $d->subject_id || ! isset(self::SUBJECT_MODELS[$d->subject_type])) {
            return null;
        }
        [$class, $col] = self::SUBJECT_MODELS[$d->subject_type];

        return str_replace('_', ' ', $d->subject_type).' '.($class::query()->whereKey($d->subject_id)->value($col) ?? '');
    }
}
