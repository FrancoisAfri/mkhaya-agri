<?php

namespace App\Modules\Finance\Sars\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Sars\Models\SarsSubmission;
use App\Modules\Finance\Sars\Services\SubmissionStatusService;
use App\Modules\Finance\Sars\Support\SubmissionLifecycle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Submission history — the audit trail of every generated SARS return. Lists them, shows one
 * with its stored figures and validation result, lets a user re-download the file, advance the
 * status, and see the amendment chain. Tenant-scoped via the model (company_id + RLS).
 */
class SarsSubmissionController extends Controller
{
    public function index(Request $request): Response
    {
        $rows = SarsSubmission::query()
            ->when($request->filled('tax_type'), fn ($q) => $q->where('tax_type', $request->string('tax_type')))
            ->orderByDesc('id')->paginate(30)->withQueryString()
            ->through(fn (SarsSubmission $s) => [
                'id'           => $s->id,
                'uuid'         => $s->uuid,
                'reference'    => $s->reference,
                'tax_type'     => $s->tax_type,
                'period'       => $s->period,
                'subject'      => $s->subject_label,
                'status'       => $s->status,
                'version'      => $s->version,
                'amends_id'    => $s->amends_id,
                'totals'       => $s->totals,
                'errors'       => is_array($s->validation['errors'] ?? null) ? count($s->validation['errors']) : 0,
                'has_file'     => (bool) $s->file_path,
                'generated_at' => $s->generated_at?->toDateTimeString(),
            ]);

        return Inertia::render('Modules/Finance/Sars/Submissions', [
            'submissions' => $rows,
            'filters'     => $request->only('tax_type'),
            'taxTypes'    => SarsSubmission::TAX_TYPES,
            'statuses'    => SarsSubmission::STATUSES,
        ]);
    }

    public function show(SarsSubmission $submission): Response
    {
        return Inertia::render('Modules/Finance/Sars/SubmissionShow', [
            'submission' => [
                'id'           => $submission->id,
                'uuid'         => $submission->uuid,
                'reference'    => $submission->reference,
                'tax_type'     => $submission->tax_type,
                'period'       => $submission->period,
                'subject'      => $submission->subject_label,
                'status'       => $submission->status,
                'version'      => $submission->version,
                'totals'       => $submission->totals,
                'payload'      => $submission->payload,
                'validation'   => $submission->validation,
                'notes'        => $submission->notes,
                'has_file'     => (bool) $submission->file_path,
                'generated_at' => $submission->generated_at?->toDateTimeString(),
                'submitted_at' => $submission->submitted_at?->toDateTimeString(),
            ],
            // Only the moves this return can actually make. Offering all six and refusing five of
            // them makes the user discover the rule by being told off.
            'statuses'   => SubmissionLifecycle::nextFrom($submission->status),
            'isTerminal' => SubmissionLifecycle::isTerminal($submission->status),
            // Who moved it, from what, to what, and when.
            'events'     => $submission->events()->orderBy('id')->get()
                ->map(fn ($e) => [
                    'id'          => $e->id,
                    'description' => $e->describe(),
                    'from_status' => $e->from_status,
                    'to_status'   => $e->to_status,
                    'note'        => $e->note,
                    'actor'       => $e->actor_name,
                    'at'          => $e->created_at?->toDateTimeString(),
                ]),
            // Full version history for this return (this row + everything that amends it, and its chain).
            'versions'   => SarsSubmission::query()
                ->where('tax_type', $submission->tax_type)->where('period', $submission->period)
                ->orderBy('version')->get(['id', 'reference', 'version', 'status', 'generated_at'])
                ->map(fn ($v) => ['id' => $v->id, 'reference' => $v->reference, 'version' => $v->version, 'status' => $v->status, 'generated_at' => $v->generated_at?->toDateString()]),
        ]);
    }

    /**
     * Advance a return to its next status.
     *
     * The move itself is decided by SubmissionLifecycle, not here: a return that has been filed
     * with SARS cannot be walked back to draft, and a rejected one is corrected by generating a
     * new version rather than by being edited back into life.
     */
    public function setStatus(Request $request, SarsSubmission $submission, SubmissionStatusService $status): RedirectResponse
    {
        $data = $request->validate([
            'status' => 'required|in:' . implode(',', SarsSubmission::STATUSES),
            'notes'  => 'nullable|string|max:2000',
        ]);

        $status->move($submission, $data['status'], $data['notes'] ?? null);

        return back()->with('success', 'Status updated to ' . $data['status'] . '.');
    }

    public function download(SarsSubmission $submission): StreamedResponse
    {
        abort_unless($submission->file_path && Storage::exists($submission->file_path), 404, 'No file stored for this submission.');
        $ext = pathinfo($submission->file_path, PATHINFO_EXTENSION) ?: 'txt';

        return Storage::download($submission->file_path, "{$submission->reference}.{$ext}");
    }
}
