<?php

namespace App\Modules\Finance\Tax\Services;

use App\Modules\Finance\Sars\Models\SarsSubmission;
use App\Modules\Finance\Sars\Services\SubmissionStatusService;
use App\Modules\Finance\Tax\Exceptions\TaxControlException;
use App\Modules\Finance\Tax\Models\TaxLiability;
use App\Modules\Finance\Tax\Models\TaxPeriod;
use App\Modules\Finance\Tax\Models\TaxReconciliation;
use App\Modules\Finance\Tax\Models\TaxReturn;
use App\Modules\Finance\Tax\Models\TaxReturnForm;
use App\Modules\Finance\Tax\Models\TaxReturnLine;
use App\Modules\Finance\Tax\Models\TaxReturnSource;
use App\Modules\Finance\Tax\Models\TaxTransaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Tax returns (§10) and their submission (§11).
 *
 *   prepare        draw the return from the period's register through the form's versioned mapping;
 *                  every field remembers the register lines (and their versions) it came from.
 *   adjust         change a field with a reason; the calculated value is kept beside it.
 *   send / review  preparer → reviewer → approver, three different people (§10.3).
 *   approve        only when validation passes and the required evidence is attached; creates the
 *                  liability and the entry in the SARS submission register.
 *   submission     recorded from evidence: a reference for "submitted", the acknowledgement for
 *                  "accepted". A sent request alone never makes a return accepted (TAX-SUB-010),
 *                  and an unknown outcome stays unknown until someone confirms it (TAX-013).
 *
 * Nothing here edits a source document or a ledger entry (TAX-RET-002).
 */
class TaxReturnService
{
    private const FORM_FOR = ['VAT' => 'VAT201', 'PAYE' => 'EMP201'];

    /** The register's tax types each form reads from its period. */
    private const SOURCE_TYPES = ['VAT201' => ['VAT'], 'EMP201' => ['PAYE', 'UIF', 'SDL', 'ETI']];

    public function __construct(
        private readonly TaxAudit $audit,
        private readonly TaxPeriodService $periods,
        private readonly TaxRegistrationService $registrations,
        private readonly SubmissionStatusService $submissions,
    ) {
    }

    // ── Preparation ──────────────────────────────────────────────────────────

    public function prepare(TaxPeriod $period): TaxReturn
    {
        $code = self::FORM_FOR[$period->taxType?->code] ?? null;
        if (! $code) {
            throw new TaxControlException("{$period->taxType?->name} has no return form in this release.");
        }
        $this->periods->assertProcessable($period);
        $form = TaxReturnForm::forDate($code, $period->end_date)
            ?? throw new TaxControlException("No {$code} form is in force for {$period->label}.");

        return DB::transaction(function () use ($period, $form) {
            $previous = TaxReturn::query()->where('period_id', $period->id)->orderByDesc('version')->lockForUpdate()->first();
            $isAmendment = $previous && in_array($previous->status, ['approved', 'submitted', 'accepted', 'rejected'], true)
                && $previous->status !== 'rejected';
            // A rejected return is corrected by a new original-purpose version; an approved or filed
            // one only by an amendment, which needs the period reopened first (assertProcessable).

            if ($previous && ! in_array($previous->status, ['rejected', 'superseded'], true)) {
                $previous->forceFill(['status' => 'superseded', 'submission_status' => $previous->submission_status === 'not_submitted' ? 'not_submitted' : 'amended'])->save();
            }

            $rows = $this->sourceRows($period, $form->code);
            [$lines, $sources] = $this->compute($form->fields, $rows);
            $version = ($previous?->version ?? 0) + 1;

            $return = TaxReturn::create([
                'entity_id' => $period->entity_id, 'company_id' => $period->company_id,
                'registration_id' => $period->registration_id, 'period_id' => $period->id, 'tax_type_id' => $period->tax_type_id,
                'form_id' => $form->id, 'version' => $version,
                'reference' => sprintf('RET-%s-%s-v%d', $form->code, $period->end_date->format('Y-m'), $version),
                'purpose' => $isAmendment ? 'amendment' : 'original', 'amends_id' => $isAmendment ? $previous->id : null,
                'status' => 'prepared', 'due_date' => $period->filing_due_date,
                'snapshot' => $this->snapshot($rows), 'prepared_by' => Auth::id(), 'prepared_at' => now(),
            ]);
            $previous?->forceFill(['superseded_by_id' => $return->id])->save();

            foreach (array_values($lines) as $i => $l) {
                TaxReturnLine::create($l + ['company_id' => $return->company_id, 'return_id' => $return->id, 'sort' => $i]);
            }
            foreach ($sources as $s) {
                TaxReturnSource::create($s + ['company_id' => $return->company_id, 'return_id' => $return->id]);
            }

            $this->refreshTotals($return);
            $this->audit->record('return.prepared', $return, ['period_id' => $period->id, 'tax_type' => $period->taxType->code,
                'after' => ['version' => $version, 'purpose' => $return->purpose, 'amount_payable' => $return->amount_payable,
                    'form' => "{$form->code} v{$form->version}", 'lines' => count($rows)]]);
            app(\App\Modules\Finance\Tax\Support\TaxEvents::class)->publish('tax.return.prepared', ['return_id' => $return->id, 'company_id' => $return->company_id]);

            return $return->fresh(['lines']);
        });
    }

    /** TAX-RET-003: change a field, with a reason; the calculated value stays beside it. */
    public function adjust(TaxReturn $return, string $field, string $amount, string $reason): TaxReturn
    {
        if (! in_array($return->status, ['prepared', 'draft'], true)) {
            throw new TaxControlException('Only a prepared return can be adjusted. Send it back to preparation first.');
        }
        if (mb_strlen(trim($reason)) < 10) {
            throw new TaxControlException('Explain the adjustment (at least 10 characters). It is kept with the return.');
        }
        if (! is_numeric($amount)) {
            throw new TaxControlException('The adjustment must be an amount.');
        }

        return DB::transaction(function () use ($return, $field, $amount, $reason) {
            $line = $return->lines()->where('field', $field)->firstOrFail();
            if ($line->kind === 'total') {
                throw new TaxControlException('A total is calculated from its fields; adjust the fields instead.');
            }
            $before = $line->only(['adjustment', 'final']);
            $line->forceFill(['adjustment' => bcadd($amount, '0', 2), 'final' => bcadd((string) $line->calculated, $amount, 2),
                'adjustment_reason' => $reason, 'adjusted_by' => Auth::id(), 'adjusted_at' => now()])->save();
            $this->refreshTotals($return);
            $this->audit->record('return.adjusted', $return, ['period_id' => $return->period_id, 'reason' => $reason,
                'before' => $before + ['field' => $field], 'after' => $line->only(['adjustment', 'final'])]);

            return $return->fresh(['lines']);
        });
    }

    public function explain(TaxReturn $return, string $field, string $explanation): void
    {
        if ($return->isLocked()) {
            throw new TaxControlException('This return is fixed; explanations are added before approval.');
        }
        $return->lines()->where('field', $field)->update(['explanation' => $explanation]);
        $this->audit->record('return.explained', $return, ['period_id' => $return->period_id, 'reason' => $explanation, 'context' => ['field' => $field]]);
    }

    // ── Validation (TAX-RET-004/005/006) ─────────────────────────────────────

    /** @return array{errors: list<string>, warnings: list<string>, comparison: list<array<string, string>>} */
    public function validate(TaxReturn $return): array
    {
        $errors = [];
        $warnings = [];
        $period = $return->period;

        foreach ($this->registrations->missingDetails($return->registration) as $m) {
            $errors[] = "The registration needs {$m}.";
        }

        $rows = $this->sourceRows($period, $return->form->code);
        $exceptions = $rows->where('has_exception', true)->count();
        if ($exceptions) {
            $errors[] = "{$exceptions} source line(s) have unresolved exceptions.";
        }
        if ($this->snapshot($rows)['hash'] !== ($return->snapshot['hash'] ?? null)) {
            $errors[] = 'The register has changed since this return was prepared. Prepare it again.';
        }

        $mapped = $return->sources()->distinct()->pluck('transaction_id')->all();
        $unmapped = $rows->reject(fn ($r) => in_array($r->id, $mapped, true) || bccomp((string) $r->tax_amount, '0', 2) === 0 && bccomp((string) $r->taxable_amount, '0', 2) === 0)->count();
        if ($unmapped) {
            $errors[] = "{$unmapped} source line(s) do not fall into any field of the {$return->form->code} mapping.";
        }

        if ($return->form->code === 'VAT201') {
            $recon = TaxReconciliation::query()->where('period_id', $period->id)->where('type', 'vat_register_to_gl')->orderByDesc('version')->first();
            if (! $recon || $recon->status !== 'signed_off') {
                $errors[] = 'The VAT reconciliation for this period is not signed off.';
            }
            // TAX-VAT-010: return totals against the register, every difference explained.
            $lines = $return->lines->keyBy('field');
            foreach (['13' => 'output', '19' => 'input'] as $field => $direction) {
                $register = array_reduce($rows->where('direction', $direction)->all(), fn ($c, $r) => bcadd($c, (string) $r->tax_amount, 2), '0');
                $onReturn = (string) ($lines[$field]->final ?? '0');
                if (bccomp($register, $onReturn, 2) !== 0) {
                    $explained = $lines->filter(fn ($l) => bccomp((string) $l->adjustment, '0', 2) !== 0)->every(fn ($l) => filled($l->adjustment_reason));
                    $message = "Field {$field} ({$onReturn}) differs from the register ({$register})" . ($explained ? ' by explained adjustments.' : '.');
                    if ($explained) {
                        $warnings[] = $message;
                    } else {
                        $errors[] = $message;
                    }
                }
            }
        }

        $comparison = [];
        $prior = TaxReturn::query()->where('period_id', $period->id)->where('id', '!=', $return->id)
            ->whereIn('submission_status', ['submitted', 'accepted', 'amended'])->orderByDesc('version')->first();
        if ($prior) {
            $old = $prior->lines->keyBy('field');
            foreach ($return->lines as $l) {
                $was = (string) ($old[$l->field]->final ?? '0');
                if (bccomp($was, (string) $l->final, 2) !== 0) {
                    $comparison[] = ['field' => $l->field, 'label' => $l->label, 'submitted' => $was, 'now' => (string) $l->final];
                }
            }
            if ($comparison) {
                $warnings[] = 'This return differs from the version already submitted (v' . $prior->version . ') on ' . count($comparison) . ' field(s).';
            }
        }

        $result = ['errors' => $errors, 'warnings' => $warnings, 'comparison' => $comparison];
        $return->forceFill(['validation' => $result + ['checked_at' => now()->toIso8601String()]])->save();

        return $result;
    }

    // ── Workflow (§10.3) ─────────────────────────────────────────────────────

    public function sendForReview(TaxReturn $return): TaxReturn
    {
        $this->expect($return, ['prepared'], 'Only a prepared return is sent for review.');
        $result = $this->validate($return);
        if ($result['errors']) {
            throw new TaxControlException('Fix these before review: ' . implode(' ', $result['errors']));
        }

        return DB::transaction(function () use ($return) {
            $return->forceFill(['status' => 'under_review'])->save();
            if ($return->period->status === 'in_preparation') {
                $this->periods->transition($return->period, 'under_review', "Return {$return->reference} sent for review.");
            }
            $this->audit->record('return.sent_for_review', $return, ['period_id' => $return->period_id]);
            app(\App\Modules\Finance\Tax\Support\TaxEvents::class)->publish('tax.return.ready_for_review', ['return_id' => $return->id, 'company_id' => $return->company_id]);

            return $return;
        });
    }

    /** The reviewer either passes the return to the approver or sends it back, always with a comment. */
    public function review(TaxReturn $return, bool $pass, string $comment): TaxReturn
    {
        $this->expect($return, ['under_review'], 'Only a return under review is reviewed.');
        if ($return->reviewed_by && $pass) {
            throw new TaxControlException('This return has already been reviewed and is waiting for approval.');
        }
        $this->segregate($return, 'review', [$return->prepared_by]);
        if (mb_strlen(trim($comment)) < 3) {
            throw new TaxControlException('Add a review comment.');
        }

        return DB::transaction(function () use ($return, $pass, $comment) {
            if ($pass) {
                $return->forceFill(['reviewed_by' => Auth::id(), 'reviewed_at' => now(), 'review_comment' => $comment])->save();
            } else {
                $return->forceFill(['status' => 'prepared', 'reviewed_by' => null, 'reviewed_at' => null, 'review_comment' => $comment])->save();
                if ($return->period->status === 'under_review') {
                    $this->periods->transition($return->period, 'in_preparation', 'Return sent back: ' . $comment);
                }
            }
            $this->audit->record($pass ? 'return.reviewed' : 'return.returned', $return, ['period_id' => $return->period_id, 'reason' => $comment]);

            return $return;
        });
    }

    public function approve(TaxReturn $return, ?string $comment = null): TaxReturn
    {
        $this->expect($return, ['under_review'], 'Only a reviewed return can be approved.');
        if (! $return->reviewed_by) {
            throw new TaxControlException('The return has not been reviewed yet.');
        }
        $this->segregate($return, 'approve', [$return->prepared_by, $return->reviewed_by]);

        $result = $this->validate($return);
        if ($result['errors']) {
            throw new TaxControlException('This return cannot be approved: ' . implode(' ', $result['errors']));
        }
        if ($missing = $this->missingEvidence($return, 'approve')) {
            throw new TaxControlException('Attach the required evidence first: ' . implode(', ', $missing) . '.');
        }

        return DB::transaction(function () use ($return, $comment) {
            $return->forceFill(['status' => 'approved', 'approved_by' => Auth::id(), 'approved_at' => now(), 'approval_comment' => $comment])->save();

            // The period follows the return. The period service re-checks its own controls,
            // including that whoever sent it for review is not the one approving it.
            $period = $return->period->fresh();
            if ($period->status === 'under_review') {
                $this->periods->transition($period, 'approved', "Return {$return->reference} approved.");
            }

            $submission = $this->registerSubmission($return);
            $return->forceFill(['sars_submission_id' => $submission->id])->save();
            $this->createLiability($return);

            $this->audit->record('return.approved', $return, ['period_id' => $return->period_id, 'reason' => $comment,
                'after' => ['amount_payable' => (string) $return->amount_payable, 'sars_submission' => $submission->reference]]);
            app(\App\Modules\Finance\Tax\Support\TaxEvents::class)->publish('tax.return.approved', ['return_id' => $return->id, 'company_id' => $return->company_id]);

            return $return->fresh();
        });
    }

    // ── Submission (§11) ─────────────────────────────────────────────────────

    /**
     * Record that the return was sent. `submitted` needs the SARS reference; `pending` (sent,
     * awaiting acknowledgement) and `unknown` (sent, outcome unknown — e.g. eFiling timed out)
     * record the attempt without claiming it arrived, so nobody re-files blindly (§24).
     */
    public function recordSubmission(TaxReturn $return, string $outcome, string $method, ?string $reference, ?string $note = null, bool $checkedEfiling = false): TaxReturn
    {
        // §24: after an unknown outcome, sending again could file the return twice. A new attempt is
        // recorded only once someone confirms they checked eFiling and it was not received; finding
        // it there is recorded as `submitted` with its reference instead.
        if ($return->submission_status === 'unknown' && $outcome !== 'submitted' && ! $checkedEfiling) {
            throw new TaxControlException('The last attempt\'s outcome is unknown. Check eFiling first: if it arrived, record it as submitted with its reference; if not, confirm you checked before sending again.');
        }
        $this->expect($return, ['approved', 'submitted'], 'Only an approved return is submitted.');
        if (! in_array($outcome, ['submitted', 'pending', 'unknown'], true)) {
            throw new TaxControlException('Unknown submission outcome.');
        }
        if ($outcome === 'submitted' && blank($reference)) {
            throw new TaxControlException('A return is recorded as submitted only with the SARS reference number.');
        }
        if ($return->submission_status === 'submitted' && $outcome !== 'submitted') {
            throw new TaxControlException('This return is already recorded as submitted.');
        }

        return DB::transaction(function () use ($return, $outcome, $method, $reference, $note, $checkedEfiling) {
            $before = ['submission_status' => $return->submission_status];
            if ($checkedEfiling) {
                $note = trim('Checked eFiling before re-sending: not received. ' . ($note ?? ''));
            }
            $return->forceFill(['submission_status' => $outcome, 'submission_method' => $method,
                'submission_reference' => $reference ?: $return->submission_reference, 'submission_response' => $note,
                'submitted_by' => Auth::id(), 'submitted_at' => $outcome === 'submitted' ? now() : $return->submitted_at,
                'status' => $outcome === 'submitted' ? 'submitted' : $return->status])->save();

            if (in_array($outcome, ['unknown', 'pending'], true)) {
                $this->notifyPeople($return, $outcome === 'unknown' ? 'unknown' : 'unconfirmed');
            }

            if ($outcome === 'submitted') {
                // §14 milestone: the filing deadline for this period is met.
                app(ComplianceCalendarService::class)->completeFor($return->period_id, 'filing', "Return {$return->reference} submitted (SARS reference {$reference}).");
                $sub = SarsSubmission::find($return->sars_submission_id);
                if ($sub && $sub->status === 'validated') {
                    $this->submissions->move($sub, 'submitted', "SARS reference {$reference}");
                }
                $period = $return->period->fresh();
                if ($period->status === 'approved') {
                    $this->periods->transition($period, 'submitted', null, ['reference' => $reference]);
                }
            }

            $this->audit->record('return.submission_' . $outcome, $return, ['period_id' => $return->period_id, 'reason' => $note,
                'before' => $before, 'after' => ['submission_status' => $outcome, 'reference' => $reference, 'method' => $method]]);
            app(\App\Modules\Finance\Tax\Support\TaxEvents::class)->publish('tax.return.submission_status_changed', ['return_id' => $return->id, 'company_id' => $return->company_id, 'status' => $outcome]);

            return $return;
        });
    }

    /** SARS's answer, from its acknowledgement or rejection notice. */
    public function recordOutcome(TaxReturn $return, string $outcome, string $reference, ?string $response = null): TaxReturn
    {
        $this->expect($return, ['submitted'], 'Record the submission before its outcome.');
        if (! in_array($outcome, ['accepted', 'rejected'], true) || blank($reference)) {
            throw new TaxControlException('Record the outcome with SARS\'s acknowledgement or rejection reference.');
        }
        if ($outcome === 'accepted' && $this->missingEvidence($return, 'submitted')) {
            throw new TaxControlException('Attach the SARS submission receipt before recording acceptance.');
        }

        return DB::transaction(function () use ($return, $outcome, $reference, $response) {
            $return->forceFill(['status' => $outcome, 'submission_status' => $outcome,
                'submission_response' => trim(($return->submission_response ? $return->submission_response . "\n" : '') . "{$outcome}: {$reference} " . ($response ?? ''))])->save();

            $sub = SarsSubmission::find($return->sars_submission_id);
            if ($sub && $sub->status === 'submitted') {
                $this->submissions->move($sub, $outcome, "SARS {$outcome}: {$reference}");
            }
            $period = $return->period->fresh();
            if ($period->status === 'submitted') {
                $this->periods->transition($period, $outcome, $response, ['reference' => $reference]);
            }
            if ($outcome === 'rejected') {
                app(\App\Modules\Finance\Tax\Support\TaxEvents::class)->publish('tax.return.rejected', ['return_id' => $return->id, 'company_id' => $return->company_id]);
                $this->notifyPeople($return, 'rejected');
            }
            $this->audit->record('return.' . $outcome, $return, ['period_id' => $return->period_id, 'reason' => $response,
                'after' => ['reference' => $reference]]);
            app(\App\Modules\Finance\Tax\Support\TaxEvents::class)->publish('tax.return.submission_status_changed', ['return_id' => $return->id, 'company_id' => $return->company_id, 'status' => $outcome]);

            return $return;
        });
    }

    /**
     * TAX-SUB-008: chase every submission still pending or unknown after `$days` days — once a day,
     * until someone records the reference or the outcome.
     */
    public function chaseUnconfirmed(?int $days = null): int
    {
        $days ??= (int) app(\App\Modules\Finance\Tax\Support\TaxSettings::class)->get('chase_unconfirmed_after_days');
        $count = 0;
        $stale = TaxReturn::query()->whereIn('submission_status', ['pending', 'unknown'])->where('updated_at', '<', now()->subDays($days))->get();
        foreach ($stale as $return) {
            $chasedToday = \App\Modules\Finance\Tax\Models\TaxAuditEvent::query()->where('subject_type', 'TaxReturn')->where('subject_id', $return->id)
                ->where('action', 'return.submission_chased')->where('created_at', '>=', now()->subDay())->exists();
            if ($chasedToday) {
                continue;
            }
            $this->notifyPeople($return, $return->submission_status === 'unknown' ? 'unknown' : 'unconfirmed');
            $this->audit->record('return.submission_chased', $return, ['period_id' => $return->period_id, 'service' => 'tax:compliance',
                'after' => ['submission_status' => $return->submission_status]]);
            $count++;
        }

        return $count;
    }

    private function notifyPeople(TaxReturn $return, string $kind): void
    {
        $ids = array_unique(array_filter([$return->prepared_by, $return->reviewed_by, $return->approved_by, $return->registration?->escalation_id]));
        foreach (\App\Core\User\Models\User::query()->whereIn('id', $ids)->get() as $user) {
            try {
                $user->notify(new \App\Modules\Finance\Tax\Notifications\TaxSubmissionNotification($return, $kind));
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    // ── Evidence (§15) ───────────────────────────────────────────────────────

    /** @return list<array{key:string, label:string, required_for:?string, documents:list<array{id:int,title:string}>}> */
    public function evidence(TaxReturn $return): array
    {
        $links = $return->documentLinks()->with('document:id,title')->get();

        return array_map(fn ($item) => $item + ['documents' => $links->where('relation', 'tax:' . $item['key'])
            ->map(fn ($l) => ['id' => $l->document_id, 'title' => $l->document?->title ?? ('Document #' . $l->document_id)])->values()->all()],
            $return->form->evidence ?? []);
    }

    /** @return list<string> labels of evidence required at this step and not attached */
    public function missingEvidence(TaxReturn $return, string $step): array
    {
        return collect($this->evidence($return))
            ->filter(fn ($e) => $e['required_for'] === $step && $e['documents'] === [])
            ->pluck('label')->values()->all();
    }

    /**
     * File evidence against the return through Document Management. Once a return is approved, its
     * approval evidence is part of what was approved: adding to it is allowed, replacing it is an
     * amendment (§15), so nothing here ever detaches a document.
     */
    public function attachEvidence(TaxReturn $return, string $key, \App\Modules\Operations\DocumentManagement\Models\Document $document): void
    {
        if (! collect($return->form->evidence ?? [])->contains('key', $key)) {
            throw new TaxControlException('Unknown evidence item.');
        }
        $return->attachDocument($document, 'tax:' . $key);
        $this->audit->record('return.evidence_attached', $return, ['period_id' => $return->period_id,
            'context' => ['item' => $key, 'document_id' => $document->id, 'title' => $document->title]]);
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /** @return Collection<int, TaxTransaction> */
    public function sourceRows(TaxPeriod $period, string $formCode): Collection
    {
        $types = self::SOURCE_TYPES[$formCode] ?? [];

        return TaxTransaction::query()->current()->where('period_id', $period->id)->where('status', '!=', 'excluded')
            ->whereHas('taxType', fn ($q) => $q->whereIn('code', $types))->with('taxType:id,code')->orderBy('id')->get();
    }

    /** Apply the form mapping. @return array{0: array<string, array>, 1: list<array>} */
    private function compute(array $fields, Collection $rows): array
    {
        $lines = [];
        $sources = [];

        foreach ($fields as $f) {
            $src = $f['source'];
            if (isset($src['sum'])) {
                $lines[$f['field']] = ['field' => $f['field'], 'label' => $f['label'], 'kind' => 'total', 'calculated' => '0', 'final' => '0', 'source_count' => 0, '_sum' => $src['sum'], '_less' => $src['less'] ?? []];
                continue;
            }
            $matched = $rows->filter(fn (TaxTransaction $r) => $this->matches($r, $src));
            $measure = ($src['measure'] ?? 'tax') === 'taxable' ? 'taxable_amount' : 'tax_amount';
            $total = '0';
            foreach ($matched as $r) {
                $total = bcadd($total, (string) $r->{$measure}, 2);
                $sources[] = ['field' => $f['field'], 'transaction_id' => $r->id, 'transaction_version' => $r->version, 'amount' => (string) $r->{$measure}];
            }
            $lines[$f['field']] = ['field' => $f['field'], 'label' => $f['label'], 'kind' => $f['kind'], 'calculated' => bcadd($total, '0', 2),
                'final' => bcadd($total, '0', 2), 'source_count' => $matched->count()];
        }

        // Totals after every field is known, in form order (a total may use an earlier total).
        foreach ($lines as $field => $l) {
            if (! isset($l['_sum'])) {
                continue;
            }
            $v = '0';
            foreach ($l['_sum'] as $s) {
                $v = bcadd($v, (string) ($lines[$s]['final'] ?? '0'), 2);
            }
            foreach ($l['_less'] as $s) {
                $v = bcsub($v, (string) ($lines[$s]['final'] ?? '0'), 2);
            }
            $lines[$field]['calculated'] = $v;
            $lines[$field]['final'] = $v;
            unset($lines[$field]['_sum'], $lines[$field]['_less']);
        }

        return [$lines, $sources];
    }

    private function matches(TaxTransaction $r, array $src): bool
    {
        if (isset($src['tax_type']) && $r->taxType?->code !== $src['tax_type']) {
            return false;
        }
        if (isset($src['direction']) && $r->direction !== $src['direction']) {
            return false;
        }
        if (isset($src['treatment']) && ! in_array($r->treatment, (array) $src['treatment'], true)) {
            return false;
        }
        if (isset($src['return_field']) && $r->return_field !== $src['return_field']) {
            return false;
        }
        if (isset($src['not_return_field']) && $r->return_field === $src['not_return_field']) {
            return false;
        }

        return true;
    }

    /** Recompute totals from field values after an adjustment, and the amount payable. */
    private function refreshTotals(TaxReturn $return): void
    {
        $lines = $return->lines()->get()->keyBy('field');
        foreach ($return->form->fields as $f) {
            if (! isset($f['source']['sum'])) {
                continue;
            }
            $v = '0';
            foreach ($f['source']['sum'] as $s) {
                $v = bcadd($v, (string) ($lines[$s]->final ?? '0'), 2);
            }
            foreach ($f['source']['less'] ?? [] as $s) {
                $v = bcsub($v, (string) ($lines[$s]->final ?? '0'), 2);
            }
            $lines[$f['field']]->forceFill(['calculated' => $v, 'final' => $v])->save();
        }

        $payableField = collect($return->form->fields)->last()['field'];
        $return->forceFill([
            'amount_payable' => (string) $lines[$payableField]->final,
            'totals' => $lines->mapWithKeys(fn ($l) => [$l->field => (string) $l->final])->all(),
        ])->save();
    }

    /** What the return was built from: enough to tell, later, whether the register has moved. */
    private function snapshot(Collection $rows): array
    {
        return [
            'lines' => $rows->count(),
            'hash' => hash('sha256', $rows->map(fn ($r) => $r->id . ':' . $r->version . ':' . $r->tax_amount . ':' . $r->taxable_amount . ':' . ($r->has_exception ? 1 : 0))->implode('|')),
            'taken_at' => now()->toIso8601String(),
        ];
    }

    private function registerSubmission(TaxReturn $return): SarsSubmission
    {
        $code = strtolower($return->form->code);
        $period = $return->period;
        $prior = SarsSubmission::ownReturns()->where('tax_type', $code)->where('period', $period->end_date->format('Y-m'))->orderByDesc('version')->first();

        $sub = SarsSubmission::create([
            'company_id' => $return->company_id, 'tax_type' => $code, 'period' => $period->end_date->format('Y-m'),
            'status' => 'generated', 'version' => ($prior?->version ?? 0) + 1, 'amends_id' => $prior?->id,
            'totals' => $return->totals, 'payload' => ['tax_return' => $return->reference, 'lines' => $return->lines()->get(['field', 'label', 'final'])->toArray()],
            'validation' => $return->validation, 'notes' => "Prepared in Tax Management as {$return->reference}",
            'generated_by' => Auth::id(), 'generated_at' => now(),
        ]);
        $this->submissions->recordGenerated($sub, "From {$return->reference}");
        $this->submissions->move($sub, 'validated', 'Approved in Tax Management');

        return $sub->fresh();
    }

    /** §13: the approved amount becomes a liability; an amendment adds only its difference. */
    private function createLiability(TaxReturn $return): void
    {
        $amount = (string) $return->amount_payable;
        $description = "{$return->form->code} {$return->period->label}";
        $supersedes = null;

        if ($return->purpose === 'amendment' && $return->amends_id) {
            $earlier = TaxLiability::query()->where('return_id', $return->amends_id)->first();
            if ($earlier) {
                $amount = bcsub($amount, bcadd((string) $earlier->original_amount, (string) $earlier->adjustments, 2), 2);
                $description .= " — amendment v{$return->version}";
                $supersedes = $earlier->id;
            }
        }

        if (bccomp($amount, '0', 2) === 0) {
            return;
        }

        $liability = TaxLiability::create([
            'entity_id' => $return->entity_id, 'company_id' => $return->company_id, 'tax_type_id' => $return->tax_type_id,
            'registration_id' => $return->registration_id, 'period_id' => $return->period_id, 'return_id' => $return->id,
            'source' => 'return', 'description' => $description, 'original_amount' => $amount,
            'due_date' => $return->period->payment_due_date, 'status' => bccomp($amount, '0', 2) < 0 ? 'refund_due' : 'open',
            'supersedes_id' => $supersedes, 'created_by' => Auth::id(),
        ]);
        $return->forceFill(['payment_status' => bccomp($amount, '0', 2) < 0 ? 'refund_due' : 'unpaid'])->save();
        $this->audit->record('liability.recorded', $liability, ['period_id' => $return->period_id, 'after' => ['amount' => $amount, 'return' => $return->reference]]);
        app(\App\Modules\Finance\Tax\Support\TaxEvents::class)->publish('tax.liability.recorded', ['liability_id' => $liability->id, 'company_id' => $liability->company_id]);
    }

    /** Segregation of duties (§10.3, §16): the same person may not hold two of the three roles. */
    private function segregate(TaxReturn $return, string $step, array $others): void
    {
        if (in_array(Auth::id(), array_filter($others), true)) {
            $this->audit->denied("return.{$step}", $return, 'Segregation of duties: the same person cannot prepare, review and approve.', ['period_id' => $return->period_id]);
            throw new TaxControlException($step === 'review'
                ? 'You prepared this return, so someone else has to review it.'
                : 'You prepared or reviewed this return, so someone else has to approve it.');
        }
    }

    private function expect(TaxReturn $return, array $statuses, string $message): void
    {
        if (! in_array($return->status, $statuses, true)) {
            throw new TaxControlException($message);
        }
    }
}
