<?php

namespace App\Modules\Fleet\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Fleet\Models\FleetJobCard;
use App\Modules\Fleet\Models\FleetJobCardCatalogPart;
use App\Modules\Fleet\Models\FleetJobCardDocument;
use App\Modules\Fleet\Models\FleetJobCardImage;
use App\Modules\Fleet\Models\FleetJobCardNote;
use App\Modules\Fleet\Models\FleetJobCardPart;
use App\Modules\Fleet\Models\FleetJobCardPartCategory;
use App\Modules\Fleet\Models\FleetJobCardServiceType;
use App\Modules\Fleet\Models\FleetJobCardServiceTypeLine;
use App\Modules\Fleet\Models\FleetJobCardSetting;
use App\Modules\Fleet\Models\FleetVehicle;
use App\Modules\Fleet\Services\FleetJobCardWorkflow;
use App\Modules\HR\Hr\Models\HRPerson;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Job cards: the workshop's record of work on a vehicle.
 *
 * Status changes never happen here directly — they go through
 * FleetJobCardWorkflow so the legal-transition table and the history trail
 * stay authoritative.
 */
class FleetJobCardController extends Controller
{
    public function __construct(private readonly FleetJobCardWorkflow $workflow) {}

    // ── Listing ───────────────────────────────────────────────────────────────

    public function index(Request $request): Response
    {
        return Inertia::render('Modules/Fleet/JobCards/Index', [
            'cards'      => $this->paginatedCards($request),
            'filters'    => $request->only(['search', 'status', 'priority', 'vehicle_id', 'mechanic_id', 'from', 'to']),
            'statuses'   => $this->statusList(),
            'vehicles'   => $this->vehicleOptions(),
            'mechanics'  => $this->mechanicOptions(),
            'counts'     => $this->statusCounts(),
        ]);
    }

    /** Cards assigned to the signed-in user as mechanic. */
    public function myCards(Request $request): Response
    {
        $personId = optional(Auth::user()?->person)->id;

        return Inertia::render('Modules/Fleet/JobCards/MyCards', [
            'cards'    => $this->paginatedCards($request, fn ($q) => $q->where('mechanic_id', $personId)),
            'filters'  => $request->only(['search', 'status']),
            'statuses' => $this->statusList(),
        ]);
    }

    /** Search screen — same query surface as the index, rendered on its own page. */
    public function search(Request $request): Response
    {
        return Inertia::render('Modules/Fleet/JobCards/Search', [
            'cards'     => $request->hasAny(['search', 'status', 'vehicle_id', 'mechanic_id', 'from', 'to'])
                ? $this->paginatedCards($request)
                : null,
            'filters'   => $request->only(['search', 'status', 'priority', 'vehicle_id', 'mechanic_id', 'from', 'to']),
            'statuses'  => $this->statusList(),
            'vehicles'  => $this->vehicleOptions(),
            'mechanics' => $this->mechanicOptions(),
        ]);
    }

    // ── Create / read / update / delete ───────────────────────────────────────

    public function create(): Response
    {
        return Inertia::render('Modules/Fleet/JobCards/Create', [
            'vehicles'     => $this->vehicleOptions(),
            'mechanics'    => $this->mechanicOptions(),
            'serviceTypes' => FleetJobCardServiceType::active()->orderBy('name')->get(['id', 'name', 'standard_hours', 'standard_cost']),
            'settings'     => FleetJobCardSetting::current(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateCard($request);

        $card = DB::transaction(function () use ($data, $request) {
            $settings = FleetJobCardSetting::current();

            $card = FleetJobCard::create($data + [
                'card_number' => $this->workflow->nextCardNumber(),
                'labour_rate' => $data['labour_rate'] ?? $settings->default_labour_rate,
                'status'      => FleetJobCard::STATUS_DRAFT,
                'created_by'  => Auth::id(),
            ]);

            $this->syncServiceLines($card, $request->input('service_lines', []));
            $card->recalculateTotals();

            $this->workflow->log($card, 'created', 'Job card created');

            return $card;
        });

        return redirect()
            ->route('fleet.job-cards.show', $card)
            ->with('success', [
                'key' => 'fleet_job_cards.flash.created',
                'params' => ['number' => $card->card_number],
            ]);
    }

    public function show(FleetJobCard $jobCard): Response
    {
        $jobCard->load([
            'vehicle:id,uuid,registration_number,make,model',
            'parts', 'serviceLines', 'approvals.approver', 'notes.author',
            'images:id,uuid,job_card_id,caption,file_name,mime_type,file_size,created_at',
            'documents:id,uuid,job_card_id,title,document_type,file_name,mime_type,file_size,created_at',
        ]);

        return Inertia::render('Modules/Fleet/JobCards/Show', [
            'card'          => $jobCard,
            'history'       => $jobCard->history()->with('performer:id,email')->limit(200)->get(),
            'mechanics'     => $this->mechanicOptions(),
            'serviceTypes'  => FleetJobCardServiceType::active()->orderBy('name')->get(['id', 'name', 'standard_hours', 'standard_cost']),
            'catalogParts'  => FleetJobCardCatalogPart::active()->with('category:id,name')->orderBy('name')->get(['id', 'category_id', 'name', 'part_number', 'unit', 'unit_price']),
            'partCategories'=> FleetJobCardPartCategory::active()->orderBy('name')->get(['id', 'name']),
            'canEdit'       => ! $jobCard->isTerminal(),
        ]);
    }

    public function update(Request $request, FleetJobCard $jobCard): RedirectResponse
    {
        if ($jobCard->isTerminal()) {
            return back()->with('error', __('fleet_job_cards.flash.locked'));
        }

        $data = $this->validateCard($request);

        DB::transaction(function () use ($jobCard, $data, $request) {
            $jobCard->update($data + ['updated_by' => Auth::id()]);
            $this->syncServiceLines($jobCard, $request->input('service_lines', []));
            $jobCard->recalculateTotals();
            $this->workflow->log($jobCard, 'updated', 'Job card details updated');
        });

        return back()->with('success', __('fleet_job_cards.flash.updated'));
    }

    public function destroy(FleetJobCard $jobCard): RedirectResponse
    {
        $jobCard->delete();

        return redirect()
            ->route('fleet.job-cards.index')
            ->with('success', __('fleet_job_cards.flash.deleted'));
    }

    // ── Lifecycle ─────────────────────────────────────────────────────────────

    public function submit(FleetJobCard $jobCard): RedirectResponse
    {
        $status = $this->workflow->submitForApproval($jobCard);

        return back()->with('success', $status === FleetJobCard::STATUS_APPROVED
            ? __('fleet_job_cards.flash.auto_approved')
            : __('fleet_job_cards.flash.submitted'));
    }

    public function start(FleetJobCard $jobCard): RedirectResponse
    {
        if (! $this->workflow->transition($jobCard, FleetJobCard::STATUS_IN_PROGRESS, 'Work started')) {
            return back()->with('error', __('fleet_job_cards.flash.invalid_transition'));
        }

        $jobCard->forceFill(['started_at' => now()])->save();

        return back()->with('success', __('fleet_job_cards.flash.started'));
    }

    /** The mechanic's account of the work done; completes the card. */
    public function mechanicFeedback(Request $request, FleetJobCard $jobCard): RedirectResponse
    {
        $data = $request->validate([
            'mechanic_feedback' => ['required', 'string', 'max:5000'],
            'work_performed'    => ['nullable', 'string', 'max:5000'],
            'labour_hours'      => ['nullable', 'numeric', 'min:0', 'max:9999'],
        ]);

        DB::transaction(function () use ($jobCard, $data) {
            $jobCard->forceFill([
                'mechanic_feedback'    => $data['mechanic_feedback'],
                'work_performed'       => $data['work_performed'] ?? $jobCard->work_performed,
                'labour_hours'         => $data['labour_hours'] ?? $jobCard->labour_hours,
                'mechanic_feedback_at' => now(),
            ])->save();

            $jobCard->notes()->create([
                'note_type'  => 'mechanic',
                'body'       => $data['mechanic_feedback'],
                'created_by' => Auth::id(),
            ]);

            $jobCard->recalculateTotals();
            $this->workflow->log($jobCard, 'mechanic_feedback', 'Mechanic feedback recorded');

            if (! $this->workflow->transition($jobCard, FleetJobCard::STATUS_COMPLETED, 'Work completed by mechanic')) {
                $this->workflow->transition($jobCard, FleetJobCard::STATUS_AWAITING_FEEDBACK, 'Awaiting completion');
            }

            $jobCard->forceFill(['completed_at' => now()])->save();
        });

        return back()->with('success', __('fleet_job_cards.flash.feedback_recorded'));
    }

    public function close(FleetJobCard $jobCard): RedirectResponse
    {
        $settings = FleetJobCardSetting::current();

        if ($settings->require_mechanic_feedback && blank($jobCard->mechanic_feedback)) {
            return back()->with('error', __('fleet_job_cards.flash.feedback_required'));
        }

        if (! $this->workflow->transition($jobCard, FleetJobCard::STATUS_CLOSED, 'Job card closed')) {
            return back()->with('error', __('fleet_job_cards.flash.invalid_transition'));
        }

        $jobCard->forceFill(['closed_at' => now(), 'closed_by' => Auth::id()])->save();

        return back()->with('success', __('fleet_job_cards.flash.closed'));
    }

    public function cancel(Request $request, FleetJobCard $jobCard): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        if (! $this->workflow->transition($jobCard, FleetJobCard::STATUS_CANCELLED, 'Cancelled: ' . $data['reason'])) {
            return back()->with('error', __('fleet_job_cards.flash.invalid_transition'));
        }

        $jobCard->forceFill([
            'cancelled_at'        => now(),
            'cancelled_by'        => Auth::id(),
            'cancellation_reason' => $data['reason'],
        ])->save();

        $jobCard->notes()->create([
            'note_type'  => 'cancellation',
            'body'       => $data['reason'],
            'created_by' => Auth::id(),
        ]);

        return back()->with('success', __('fleet_job_cards.flash.cancelled'));
    }

    // ── Parts ─────────────────────────────────────────────────────────────────

    public function addPart(Request $request, FleetJobCard $jobCard): RedirectResponse
    {
        if ($jobCard->isTerminal()) {
            return back()->with('error', __('fleet_job_cards.flash.locked'));
        }

        $data = $request->validate([
            'catalog_part_id' => ['nullable', 'integer', 'exists:fleet_job_card_catalog_parts,id'],
            'name'            => ['required_without:catalog_part_id', 'nullable', 'string', 'max:255'],
            'part_number'     => ['nullable', 'string', 'max:100'],
            'quantity'        => ['required', 'numeric', 'min:0.01', 'max:99999'],
            'unit_price'      => ['nullable', 'numeric', 'min:0'],
        ]);

        $catalog = ! empty($data['catalog_part_id'])
            ? FleetJobCardCatalogPart::find($data['catalog_part_id'])
            : null;

        $jobCard->parts()->create([
            'catalog_part_id' => $catalog?->id,
            'category_id'     => $catalog?->category_id,
            'name'            => $data['name'] ?? $catalog?->name,
            'part_number'     => $data['part_number'] ?? $catalog?->part_number,
            'quantity'        => $data['quantity'],
            'unit_price'      => $data['unit_price'] ?? $catalog?->unit_price ?? 0,
            'created_by'      => Auth::id(),
        ]);

        $jobCard->recalculateTotals();
        $this->workflow->log($jobCard, 'part_added', ($data['name'] ?? $catalog?->name) . ' ×' . $data['quantity']);

        return back()->with('success', __('fleet_job_cards.flash.part_added'));
    }

    public function removePart(FleetJobCard $jobCard, FleetJobCardPart $part): RedirectResponse
    {
        abort_unless($part->job_card_id === $jobCard->id, 404);

        if ($jobCard->isTerminal()) {
            return back()->with('error', __('fleet_job_cards.flash.locked'));
        }

        $part->delete();
        $jobCard->recalculateTotals();
        $this->workflow->log($jobCard, 'part_removed', $part->name);

        return back()->with('success', __('fleet_job_cards.flash.part_removed'));
    }

    // ── Notes, images, documents ──────────────────────────────────────────────

    public function addNote(Request $request, FleetJobCard $jobCard): RedirectResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        $jobCard->notes()->create([
            'note_type'  => 'general',
            'body'       => $data['body'],
            'created_by' => Auth::id(),
        ]);

        $this->workflow->log($jobCard, 'note_added');

        return back()->with('success', __('fleet_job_cards.flash.note_added'));
    }

    public function deleteNote(FleetJobCard $jobCard, FleetJobCardNote $note): RedirectResponse
    {
        abort_unless($note->job_card_id === $jobCard->id, 404);
        $note->delete();

        return back()->with('success', __('fleet_job_cards.flash.note_deleted'));
    }

    public function addImage(Request $request, FleetJobCard $jobCard): RedirectResponse
    {
        $request->validate([
            'file'    => ['required', 'file', 'image', 'max:10240'],
            'caption' => ['nullable', 'string', 'max:255'],
        ]);

        $file = $request->file('file');

        $image = $jobCard->images()->make([
            'caption'    => $request->input('caption'),
            'file_name'  => $file->getClientOriginalName(),
            'mime_type'  => $file->getMimeType(),
            'file_size'  => $file->getSize(),
            'created_by' => Auth::id(),
        ]);

        // To the shared disk, which stores raw bytes — the base64 this replaces existed because
        // Postgres rejects binary through a text parameter, and cost 33% on every photo.
        $image->writeBytes(file_get_contents($file->getRealPath()), $file->getClientOriginalExtension());
        $image->save();

        $this->workflow->log($jobCard, 'image_added', $file->getClientOriginalName());

        return back()->with('success', __('fleet_job_cards.flash.image_added'));
    }

    public function deleteImage(FleetJobCard $jobCard, FleetJobCardImage $image): RedirectResponse
    {
        abort_unless($image->job_card_id === $jobCard->id, 404);
        $image->delete();

        return back()->with('success', __('fleet_job_cards.flash.image_deleted'));
    }

    public function downloadImage(FleetJobCard $jobCard, FleetJobCardImage $image): StreamedResponse
    {
        abort_unless($image->job_card_id === $jobCard->id, 404);

        return $this->streamBinary($image->bytes(), $image->file_name ?: 'image', $image->mime_type);
    }

    public function addDocument(Request $request, FleetJobCard $jobCard): RedirectResponse
    {
        $request->validate([
            'file'          => ['required', 'file', 'max:20480', 'mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg'],
            'title'         => ['nullable', 'string', 'max:255'],
            'document_type' => ['nullable', 'string', 'max:60'],
        ]);

        $file = $request->file('file');

        $document = $jobCard->documents()->make([
            'title'         => $request->input('title') ?: $file->getClientOriginalName(),
            'document_type' => $request->input('document_type'),
            'file_name'     => $file->getClientOriginalName(),
            'mime_type'     => $file->getMimeType(),
            'file_size'     => $file->getSize(),
            'created_by'    => Auth::id(),
        ]);

        $document->writeBytes(file_get_contents($file->getRealPath()), $file->getClientOriginalExtension());
        $document->save();

        $this->workflow->log($jobCard, 'document_added', $file->getClientOriginalName());

        return back()->with('success', __('fleet_job_cards.flash.document_added'));
    }

    public function deleteDocument(FleetJobCard $jobCard, FleetJobCardDocument $document): RedirectResponse
    {
        abort_unless($document->job_card_id === $jobCard->id, 404);
        $document->delete();

        return back()->with('success', __('fleet_job_cards.flash.document_deleted'));
    }

    public function downloadDocument(FleetJobCard $jobCard, FleetJobCardDocument $document): StreamedResponse
    {
        abort_unless($document->job_card_id === $jobCard->id, 404);

        return $this->streamBinary($document->bytes(), $document->file_name ?: 'document', $document->mime_type);
    }

    // ── History & print ───────────────────────────────────────────────────────

    public function history(FleetJobCard $jobCard): Response
    {
        return Inertia::render('Modules/Fleet/JobCards/History', [
            'card'    => $jobCard->only(['id', 'uuid', 'card_number', 'status']),
            'history' => $jobCard->history()->with('performer:id,email')->paginate(50),
        ]);
    }

    /** Printable view of a single card — rendered by the front end, then printed. */
    public function print(FleetJobCard $jobCard): Response
    {
        $jobCard->load(['vehicle:id,registration_number,make,model', 'parts', 'serviceLines', 'approvals', 'notes']);

        return Inertia::render('Modules/Fleet/JobCards/Print', ['card' => $jobCard]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function paginatedCards(Request $request, ?callable $extra = null)
    {
        $query = FleetJobCard::with('vehicle:id,registration_number,make,model')->latest('opened_date');

        if ($extra) {
            $extra($query);
        }

        if ($search = trim((string) $request->query('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('card_number', 'ilike', "%{$search}%")
                    ->orWhere('title', 'ilike', "%{$search}%")
                    ->orWhere('fault_description', 'ilike', "%{$search}%")
                    ->orWhere('invoice_number', 'ilike', "%{$search}%")
                    ->orWhereHas('vehicle', fn ($v) => $v->where('registration_number', 'ilike', "%{$search}%"));
            });
        }

        foreach (['status', 'priority', 'vehicle_id', 'mechanic_id'] as $field) {
            if ($value = $request->query($field)) {
                $query->where($field, $value);
            }
        }

        if ($from = $request->query('from')) {
            $query->whereDate('opened_date', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->whereDate('opened_date', '<=', $to);
        }

        return $query->paginate(15)->withQueryString();
    }

    private function statusCounts(): array
    {
        return FleetJobCard::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();
    }

    private function statusList(): array
    {
        return [
            FleetJobCard::STATUS_DRAFT, FleetJobCard::STATUS_PENDING_APPROVAL,
            FleetJobCard::STATUS_APPROVED, FleetJobCard::STATUS_IN_PROGRESS,
            FleetJobCard::STATUS_AWAITING_FEEDBACK, FleetJobCard::STATUS_COMPLETED,
            FleetJobCard::STATUS_CLOSED, FleetJobCard::STATUS_REJECTED,
            FleetJobCard::STATUS_CANCELLED,
        ];
    }

    private function vehicleOptions()
    {
        return FleetVehicle::query()
            ->orderBy('registration_number')
            ->get(['id', 'registration_number', 'make', 'model']);
    }

    private function mechanicOptions()
    {
        return HRPerson::query()
            ->where('status', 1)
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'surname'])
            ->map(fn ($p) => ['id' => $p->id, 'name' => trim($p->first_name . ' ' . $p->surname)]);
    }

    private function validateCard(Request $request): array
    {
        return $request->validate([
            'vehicle_id'        => ['required', 'integer', 'exists:fleet_vehicles,id'],
            'maintenance_id'    => ['nullable', 'integer', 'exists:fleet_maintenance,id'],
            'mechanic_id'       => ['nullable', 'integer'],
            'driver_id'         => ['nullable', 'integer'],
            'supplier_id'       => ['nullable', 'integer'],
            'title'             => ['nullable', 'string', 'max:255'],
            'fault_description' => ['nullable', 'string', 'max:5000'],
            'work_requested'    => ['nullable', 'string', 'max:5000'],
            'work_performed'    => ['nullable', 'string', 'max:5000'],
            'priority'          => ['nullable', 'in:low,normal,high,emergency'],
            'odometer'          => ['nullable', 'numeric', 'min:0'],
            'due_date'          => ['nullable', 'date'],
            'labour_hours'      => ['nullable', 'numeric', 'min:0'],
            'labour_rate'       => ['nullable', 'numeric', 'min:0'],
            'other_cost'        => ['nullable', 'numeric', 'min:0'],
            'invoice_number'    => ['nullable', 'string', 'max:100'],
        ]);
    }

    /** Replace the card's service lines with the submitted set. */
    private function syncServiceLines(FleetJobCard $card, array $lines): void
    {
        $card->serviceLines()->delete();

        foreach ($lines as $line) {
            if (empty($line['service_type_id']) && empty($line['name'])) {
                continue;
            }

            $type = ! empty($line['service_type_id'])
                ? FleetJobCardServiceType::find($line['service_type_id'])
                : null;

            FleetJobCardServiceTypeLine::create([
                'job_card_id'     => $card->id,
                'service_type_id' => $type?->id,
                'name'            => $line['name'] ?? $type?->name,
                'hours'           => $line['hours'] ?? $type?->standard_hours ?? 0,
                'cost'            => $line['cost'] ?? $type?->standard_cost ?? 0,
            ]);
        }
    }

    /**
     * Sends already-decoded bytes as a download.
     *
     * Takes bytes rather than base64 now: decoding belongs with the storage, and having it here
     * meant the caller had to know how the file happened to be stored.
     */
    private function streamBinary(?string $binary, string $fileName, ?string $mime): StreamedResponse
    {
        abort_if($binary === null, 404);

        // Rebuilt rather than echoed — a stored name carrying a quote can otherwise close the
        // Content-Disposition header and set ones we did not intend.
        $safe = \Illuminate\Support\Str::of($fileName)
            ->replaceMatches('/[^\w.\- ]+/u', '_')->limit(200, '')->trim()->value() ?: 'file';

        return response()->streamDownload(
            fn () => print($binary),
            $safe,
            [
                // Never the file's own type on a download: a browser handed a type it recognises
                // may render it, and an uploaded page would run as this application's origin.
                'Content-Type'           => 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control'          => 'private, no-store, max-age=0',
            ]
        );
    }
}
