<?php

namespace App\Modules\Fleet\Controllers;

use App\Core\User\Trait\FileStorage;
use App\Core\User\Trait\SafeNotifies;
use App\Http\Controllers\Controller;
use App\Modules\Fleet\Models\FleetDocument;
use App\Modules\Fleet\Models\FleetVehicle;
use App\Modules\Fleet\Models\FleetVehicleReminder;
use App\Modules\Fleet\Notifications\FleetVehicleReminderNotification;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class FleetDocumentController extends Controller
{
    use FileStorage, SafeNotifies;

public function index(Request $request): InertiaResponse
{
    $query = FleetDocument::with('vehicle')
        ->orderBy('expiry_date');

    if ($search = $request->search) {
        $query->where(function ($q) use ($search) {
            $q->where('document_number', 'ilike', "%{$search}%")
              ->orWhere('issuing_authority', 'ilike', "%{$search}%")
              ->orWhereHas('vehicle', function ($vq) use ($search) {
                  $vq->where('registration_number', 'ilike', "%{$search}%")
                     ->orWhere('make', 'ilike', "%{$search}%")
                     ->orWhere('model', 'ilike', "%{$search}%");
              });
        });
    }

    if ($docType = $request->document_type) {
        $query->where('document_type', $docType);
    }

    if ($status = $request->status) {
        $query->where('status', $status);
    }

    $paginated = $query->paginate(15)->withQueryString();

    $items = $paginated->through(fn($d) => [
        'id'                => $d->id,
        'vehicle_id'        => $d->vehicle_id,
        'vehicle'           => $d->vehicle?->registration_number . ' ' . $d->vehicle?->make,
        'document_type'     => $d->document_type,
        'document_number'   => $d->document_number,
        'issue_date'        => $d->issued_date?->format('Y-m-d'),
        'expiry_date'       => $d->expiry_date?->format('Y-m-d'),
        'issuing_authority' => $d->issuing_authority,
        'status'            => $d->status,
        'days_left'         => $d->daysUntilExpiry(),
        'has_file'          => (bool) $d->file_path,
        'notes'             => $d->notes,
    ]);

    $documents = [
        'data' => $items->items(),
        'meta' => [
            'current_page' => $paginated->currentPage(),
            'last_page'    => $paginated->lastPage(),
            'total'        => $paginated->total(),
            'per_page'     => $paginated->perPage(),
        ],
        'links' => [
            'prev' => $paginated->previousPageUrl(),
            'next' => $paginated->nextPageUrl(),
        ],
    ];

    $vehicles = FleetVehicle::whereNotIn('status', ['disposed'])
        ->orderBy('registration_number')
        ->get(['id', 'registration_number', 'make', 'model'])
        ->map(fn($v) => [
            'id'           => $v->id,
            'registration' => $v->registration_number,
            'make'         => $v->make,
            'model'        => $v->model,
        ]);

    $documentTypes = \Illuminate\Support\Facades\DB::table('system_doc_types')
        ->where('is_active', true)
        ->orderBy('name')
        ->pluck('name')
        ->toArray();

    return Inertia::render('Modules/Fleet/Documents/Index', [
        'documents'     => $documents,
        'vehicles'      => $vehicles,
        'documentTypes' => $documentTypes,
        'filters'       => $request->only(['search', 'document_type', 'status']),
    ]);
}
public function store(Request $request): RedirectResponse
{
    $data = $request->validate([
        'vehicle_id'            => 'required|exists:fleet_vehicles,id',
        'document_type'         => 'required|string|max:80',
        'document_category'     => 'nullable|string|max:30',
        'supplier'              => 'nullable|string|max:150',
        'contact_person'        => 'nullable|string|max:100',
        'contact_number'        => 'nullable|string|max:60',
        'contact_email'         => 'nullable|email|max:120',
        'contact_address'       => 'nullable|string',
        'value_covered'         => 'nullable|numeric|min:0',
        'payment_frequency'     => 'nullable|in:monthly,annual,once_off',
        'premium_amount'        => 'nullable|numeric|min:0',
        'document_number'       => 'nullable|string|max:60',
        'issue_date'            => 'nullable|date',
        'expiry_date'           => 'nullable|date',
        'status'                => 'nullable|in:valid,expired,pending_renewal,revoked',
        'issuing_authority'     => 'nullable|string|max:100',
        'renewal_reminder_days' => 'nullable|integer|min:1|max:365',
        'notes'                 => 'nullable|string',
        'file'                  => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
    ]);

    if ($request->hasFile('file')) {
        $data['file_path'] = json_encode($this->saveFileToDatabase($request->file('file')));
    }

    if (isset($data['issue_date'])) {
        $data['issued_date'] = $data['issue_date'];
        unset($data['issue_date']);
    }

    $data['uuid']              = (string) Str::uuid();
    $data['status']            = $data['status'] ?? 'valid';
    $data['document_category'] = $data['document_category'] ?? 'general';
    $data['created_by'] = Auth::id();
    unset($data['file']);

    $document = FleetDocument::create($data);

    $this->scheduleExpiryReminder($document);

    return redirect()->back()->with('success', 'Document uploaded successfully.');
}

    public function update(Request $request, FleetDocument $document): RedirectResponse
    {
        $data = $request->validate([
            'document_type'         => 'required|string|max:80',
            'document_category'     => 'nullable|string|max:30',
            'supplier'              => 'nullable|string|max:150',
            'contact_person'        => 'nullable|string|max:100',
            'contact_number'        => 'nullable|string|max:60',
            'contact_email'         => 'nullable|email|max:120',
            'contact_address'       => 'nullable|string',
            'value_covered'         => 'nullable|numeric|min:0',
            'payment_frequency'     => 'nullable|in:monthly,annual,once_off',
            'premium_amount'        => 'nullable|numeric|min:0',
            'document_number'       => 'nullable|string|max:60',
            'issued_date'           => 'nullable|date',
            'expiry_date'           => 'nullable|date',
            'issuing_authority'     => 'nullable|string|max:100',
            'status'                => 'required|in:valid,expired,pending_renewal,revoked',
            'renewal_reminder_days' => 'nullable|integer|min:1|max:365',
            'notes'                 => 'nullable|string',
            'file'                  => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        if ($request->hasFile('file')) {
            $data['file_path'] = json_encode($this->saveFileToDatabase($request->file('file')));
        }

        $data['updated_by'] = Auth::id();
        $data['version']    = $document->version + 1;
        unset($data['file']);

        $hadExpiry = $document->expiry_date;
        $document->update($data);
        $document->refresh();

        if ($document->expiry_date && (string) $document->expiry_date !== (string) $hadExpiry) {
            $this->scheduleExpiryReminder($document);
        }

        return back()->with('success', 'Document updated.');
    }

    public function destroy(FleetDocument $document): RedirectResponse
    {
        $document->delete();
        return back()->with('success', 'Document removed.');
    }

    private function scheduleExpiryReminder(FleetDocument $document): void
    {
        if (!$document->expiry_date || !Auth::check()) {
            return;
        }

        $alertDays   = (int) (DB::table('fleet_settings')->where('key', 'doc_expiry_alert_days')->value('value') ?? 30);
        $vehicle     = $document->vehicle;

        if (!$vehicle) {
            return;
        }

        $expiryDate   = $document->expiry_date;
        $reminderDate = $expiryDate->copy()->subDays($alertDays)->startOfDay();

        $reminder = FleetVehicleReminder::create([
            'vehicle_id'  => $vehicle->id,
            'name'        => "{$document->document_type} Expiry — {$vehicle->registration_number}",
            'description' => "Document \"{$document->document_type}\" expires on {$expiryDate->format('d M Y')}. Please arrange renewal.",
            'start_date'  => $reminderDate,
            'end_date'    => $expiryDate,
            'notified_at' => $reminderDate,
            'created_by'  => Auth::id(),
            'updated_by'  => Auth::id(),
        ]);

        $notification = new FleetVehicleReminderNotification($reminder, $vehicle->registration_number);

        /** @var \App\Core\User\Models\User $user */
        $user = Auth::user();

        if ($reminderDate->isFuture()) {
            $this->safeNotify($user, $notification->delay($reminderDate));
        } else {
            $this->safeNotify($user, $notification);
        }
    }

    public function download(FleetDocument $document): Response
    {
        abort_unless($document->file_path, 404, 'No file attached.');

        $fileData = json_decode($document->file_path, true);
        abort_unless($fileData && isset($fileData['data']), 404, 'File data corrupt.');

        return $this->downloadResponse(
            $fileData['data'],
            $fileData['mime'],
            $fileData['original_name'] ?? 'document',
        );
    }
}
