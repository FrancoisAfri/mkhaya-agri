<?php

namespace Tests\Feature\SupplierManagement;

use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierAppraisal;
use App\Modules\SupplierManagement\Models\SupplierDocument;
use App\Modules\SupplierManagement\Models\SupplierDocumentType;
use App\Modules\SupplierManagement\Models\SupplierKycApproval;
use App\Modules\SupplierManagement\Models\SupplierPerformanceTemplate;
use App\Modules\SupplierManagement\Models\SupplierQuestionLibrary;
use App\Modules\SupplierManagement\Services\SupplierAppraisalService;
use App\Modules\SupplierManagement\Services\SupplierService;
use App\Modules\SupplierManagement\Notifications\KycReviewRequested;
use App\Modules\SupplierManagement\Notifications\SupplierDecisionNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Carbon;

/**
 * Vetting: the checks that decide whether a supplier is allowed to trade, and stay allowed.
 *
 * Everything here is about gates. A KYC decision that does not actually change what the supplier can
 * do, or a compulsory document that is never actually required, is worse than no control at all —
 * the screen says the company was vetted and the data says nobody was stopped.
 */
class SupplierVettingTest extends SupplierTestCase
{
    // ── Status transitions ───────────────────────────────────────────────────

    public function test_a_supplier_moves_through_activate_refuse_and_request_information(): void
    {
        $supplier = $this->makeSupplier(['is_active' => 0]);

        $this->post(route('suppliers.activate', $supplier))->assertRedirect();
        $this->assertSame(1, (int) $supplier->fresh()->is_active);

        $this->post(route('suppliers.refuse', $supplier))->assertRedirect();
        $this->assertSame(2, (int) $supplier->fresh()->is_active);

        // "Request more information" used to be a no-op behind a success message. It now puts the
        // supplier back to pending — so the directory shows an application genuinely waiting on
        // them — and tells them what is outstanding.
        Notification::fake();

        $this->post(route('suppliers.request-info', $supplier), ['note' => 'We still need your tax clearance.'])
            ->assertRedirect();

        $this->assertSame(0, (int) $supplier->fresh()->is_active);

        Notification::assertSentOnDemand(
            SupplierDecisionNotification::class,
            fn ($notification) => $notification->decision === 'information_requested'
                && $notification->comment === 'We still need your tax clearance.',
        );
    }

    /** A refusal reaches the supplier, with the reason attached. */
    public function test_refusing_a_supplier_tells_them_why(): void
    {
        Notification::fake();

        $supplier = $this->makeSupplier(['is_active' => 0, 'email' => 'refused@example.test']);

        $this->post(route('suppliers.refuse', $supplier), ['reason' => 'Director appears on a sanctions list.'])
            ->assertRedirect();

        Notification::assertSentOnDemand(
            SupplierDecisionNotification::class,
            fn ($notification, $channels, $notifiable) =>
                $notification->decision === 'refused'
                && $notification->comment === 'Director appears on a sanctions list.'
                && $notifiable->routes['mail'] === 'refused@example.test',
        );
    }

    public function test_a_kyc_decision_activates_or_suspends_the_supplier(): void
    {
        $approved = $this->makeSupplier(['is_active' => 3]);
        $rejected = $this->makeSupplier(['is_active' => 3]);

        $forApproval = SupplierKycApproval::create(['supplier_id' => $approved->id, 'status' => 'Pending']);
        $forRejection = SupplierKycApproval::create(['supplier_id' => $rejected->id, 'status' => 'Pending']);

        $this->post(route('suppliers.kyc.approve', $forApproval->id), ['comment' => 'Documents in order.'])->assertRedirect();
        $this->post(route('suppliers.kyc.reject', $forRejection->id), ['comment' => 'Director on a sanctions list.'])->assertRedirect();

        $this->assertSame('Approved', $forApproval->fresh()->status);
        $this->assertSame(1, (int) $approved->fresh()->is_active);

        $this->assertSame('Rejected', $forRejection->fresh()->status);
        $this->assertSame(2, (int) $rejected->fresh()->is_active);
    }

    public function test_a_kyc_decision_records_who_decided_and_when(): void
    {
        $supplier = $this->makeSupplier(['is_active' => 3]);
        $approval = SupplierKycApproval::create(['supplier_id' => $supplier->id, 'status' => 'Pending']);

        $this->post(route('suppliers.kyc.approve', $approval->id), ['comment' => 'Cleared.']);

        $approval->refresh();

        $this->assertSame($this->user->id, (int) $approval->decided_by);
        $this->assertNotNull($approval->decided_at);
        $this->assertSame('Cleared.', $approval->comment);
    }

    /**
     * The person who raised a supplier cannot clear its own KYC.
     *
     * The permission alone could never close this: everyone in the approver pool holds
     * `suppliers.approve`, so the check has to compare two people. One user creating a supplier,
     * entering its banking details and clearing its own submission is the single-operator path every
     * payments fraud takes.
     */
    public function test_kyc_requires_a_second_pair_of_eyes(): void
    {
        $supplier = $this->makeSupplier(['is_active' => 3, 'created_by' => $this->user->id]);
        $approval = SupplierKycApproval::create([
            'supplier_id' => $supplier->id, 'status' => 'Pending', 'requested_by' => $this->user->id,
        ]);

        $this->post(route('suppliers.kyc.approve', $approval->id), ['comment' => 'Looks fine to me.'])
            ->assertSessionHasErrors('status');

        $this->assertSame('Pending', $approval->fresh()->status);
        $this->assertSame(3, (int) $supplier->fresh()->is_active);

        // A colleague can.
        $reviewer = $this->makeUser($this->companyId);

        $this->actingAs($reviewer)
            ->post(route('suppliers.kyc.approve', $approval->id), ['comment' => 'Documents verified.'])
            ->assertRedirect();

        $this->assertSame('Approved', $approval->fresh()->status);
        $this->assertSame($reviewer->id, (int) $approval->fresh()->decided_by);
        $this->assertSame(1, (int) $supplier->fresh()->is_active);
    }

    /** A decision already made cannot be quietly remade. */
    public function test_a_kyc_submission_cannot_be_decided_twice(): void
    {
        $supplier = $this->makeSupplier(['is_active' => 3]);
        $approval = SupplierKycApproval::create(['supplier_id' => $supplier->id, 'status' => 'Pending']);

        $this->post(route('suppliers.kyc.approve', $approval->id))->assertRedirect();

        $this->post(route('suppliers.kyc.reject', $approval->id), ['comment' => 'Actually, no.'])
            ->assertSessionHasErrors('status');

        $this->assertSame('Approved', $approval->fresh()->status);
    }

    /** A rejection has to carry a reason — "declined" alone is a message the supplier must reply to. */
    public function test_a_kyc_rejection_requires_a_reason(): void
    {
        $supplier = $this->makeSupplier(['is_active' => 3]);
        $approval = SupplierKycApproval::create(['supplier_id' => $supplier->id, 'status' => 'Pending']);

        $this->post(route('suppliers.kyc.reject', $approval->id))->assertSessionHasErrors('comment');

        $this->assertSame('Pending', $approval->fresh()->status);
    }

    /**
     * The KYC review request must not reach approvers in other tenants.
     *
     * The fan-out used to scope on the ambient company, falling back to the acting user's. A platform
     * admin has neither — they carry no company at all — so the filter was skipped, every user in the
     * database was loaded, and one tenant's supplier name went out to approvers at every other
     * company on the platform. This is the shape of leak the platform has hit before: the scope is
     * not wrong, it is simply absent, and absent scoping looks like working code.
     */
    public function test_a_kyc_review_request_never_reaches_another_tenants_approvers(): void
    {
        Notification::fake();

        // Without this the whole fan-out is skipped: `activate()` only raises a KYC approval when the
        // tenant has KYC switched on, so a test that forgets it passes without exercising anything.
        \App\Modules\SupplierManagement\Models\SupplierSetting::query()->delete();
        \App\Modules\SupplierManagement\Models\SupplierSetting::create(['kyc' => true]);

        $otherCompany = $this->makeCompany('Rival Tenant');
        $outsider = $this->makeUser($otherCompany);
        $colleague = $this->makeUser($this->companyId);

        // A platform admin has no company of their own — the case that removed the filter entirely.
        $platformAdmin = $this->makeUser($this->companyId);
        $platformAdmin->forceFill(['company_id' => null, 'is_platform_admin' => true])->save();

        app(\App\Modules\Tenancy\Services\CurrentCompany::class)->set(null);

        $supplier = $this->makeSupplierFor($this->companyId);

        $this->actingAs($platformAdmin)->post(route('suppliers.activate', $supplier))->assertRedirect();

        // The colleague inside the tenant is the point of the queue, so they must still be told.
        Notification::assertSentTo($colleague, KycReviewRequested::class);
        Notification::assertNotSentTo($outsider, KycReviewRequested::class);
    }

    // ── Documents ────────────────────────────────────────────────────────────

    public function test_a_document_can_be_uploaded_and_downloaded_by_the_buyer(): void
    {
        $supplier = $this->makeSupplier();
        $type = SupplierDocumentType::create(['document_type_name' => 'BBBEE certificate', 'active' => true]);

        $this->post(route('suppliers.documents.store', $supplier), [
            'document_type_id' => $type->id,
            'expiry_date'      => Carbon::now()->addYear()->toDateString(),
            'file'             => UploadedFile::fake()->create('bbbee.pdf', 48, 'application/pdf'),
        ])->assertRedirect();

        $document = SupplierDocument::where('supplier_id', $supplier->id)->firstOrFail();

        $this->assertSame($this->companyId, (int) $document->company_id);
        $this->get(route('suppliers.documents.download', $document->id))->assertSuccessful();
    }

    /**
     * A compulsory document type is a label, not a rule.
     *
     * `supplier_document_types.compulsory` is set in configuration and shown on the onboarding
     * checklist, but no code path refuses to activate a supplier that is missing one, and none
     * refuses a purchase order either.
     */
    public function test_a_supplier_cannot_be_activated_with_compulsory_documents_missing(): void
    {
        $tax = SupplierDocumentType::create(['document_type_name' => 'Tax clearance', 'active' => true, 'compulsory' => true]);
        SupplierDocumentType::create(['document_type_name' => 'Public liability', 'active' => true, 'compulsory' => true]);

        $supplier = $this->makeSupplier(['is_active' => 0]);

        $this->post(route('suppliers.activate', $supplier))->assertSessionHasErrors('documents');
        $this->assertSame(0, (int) $supplier->fresh()->is_active);

        // Supplying one is not enough while the other is still outstanding.
        SupplierDocument::create([
            'supplier_id' => $supplier->id, 'document_type_id' => $tax->id, 'file_name' => 'tax.pdf',
        ]);

        $this->post(route('suppliers.activate', $supplier))->assertSessionHasErrors('documents');
        $this->assertSame(0, (int) $supplier->fresh()->is_active);
    }

    /** With the paperwork in place, activation goes through. */
    public function test_a_supplier_with_its_compulsory_documents_activates(): void
    {
        $type = SupplierDocumentType::create(['document_type_name' => 'Tax clearance', 'active' => true, 'compulsory' => true]);
        $supplier = $this->makeSupplier(['is_active' => 0]);

        SupplierDocument::create([
            'supplier_id'      => $supplier->id,
            'document_type_id' => $type->id,
            'expiry_date'      => Carbon::now()->addYear()->toDateString(),
            'file_name'        => 'tax.pdf',
        ]);

        $this->post(route('suppliers.activate', $supplier))->assertRedirect();

        $this->assertSame(1, (int) $supplier->fresh()->is_active);
    }

    /**
     * An expired compulsory document blocks the order there and then.
     *
     * The nightly `procurement:expiry-alerts` job already suspended such a supplier and raised a risk
     * flag, and that is still the right place to notice and tell somebody. What was missing was a
     * check at the moment of spend: between a certificate lapsing and the job next running, orders
     * went through, and on an installation where that job is not scheduled nothing caught it at all.
     */
    public function test_an_expired_document_blocks_a_new_order_immediately(): void
    {
        $supplier = $this->makeSupplier(['is_active' => 1]);
        $type = SupplierDocumentType::create(['document_type_name' => 'Letter of good standing', 'active' => true, 'compulsory' => true]);

        SupplierDocument::create([
            'supplier_id'      => $supplier->id,
            'document_type_id' => $type->id,
            'expiry_date'      => Carbon::now()->subMonths(6)->toDateString(),
            'file_name'        => 'lapsed.pdf',
        ]);

        $this->post(route('suppliers.purchase-orders.store'), [
            'supplier_id' => $supplier->id, 'title' => 'Work for a lapsed supplier', 'amount' => 25_000,
        ])->assertSessionHasErrors('supplier_id');

        $this->assertSame(
            0,
            \App\Modules\SupplierManagement\Models\PurchaseOrder::where('supplier_id', $supplier->id)->count(),
        );

        // The overnight control still does its half: suspend the supplier and raise a flag, so the
        // lapse is visible to a person rather than only refused at the till.
        $this->artisan('procurement:expiry-alerts')->assertSuccessful();

        $supplier->refresh();
        $this->assertSame(0, (int) $supplier->is_active, 'The nightly job should have suspended the supplier.');
        $this->assertSame(1, $supplier->riskFlags()->where('type', 'expired_compulsory_document')->count());
    }

    // ── Appraisal and scorecard ──────────────────────────────────────────────

    public function test_an_appraisal_scores_a_supplier_against_a_weighted_question_set(): void
    {
        $supplier = $this->makeSupplier();

        $template = $this->template();

        $quality = $this->question($template, 'Quality of goods delivered', weight: 60, threshold: 3);
        $delivery = $this->question($template, 'Delivered on time', weight: 40, threshold: 3);

        $appraisal = app(SupplierAppraisalService::class)->store(
            supplierId: $supplier->id,
            industryId: null,
            templateId: null,
            userId: $this->user->id,
            answers: [
                ['question_library_id' => $quality->id, 'rating' => 5],
                ['question_library_id' => $delivery->id, 'rating' => 3],
            ],
        );

        // 5/5 × 60% = 60, plus 3/5 × 40% = 24.
        $this->assertEqualsWithDelta(84.0, (float) $appraisal->total_score, 0.01);
        $this->assertSame('completed', $appraisal->status);
        $this->assertSame($this->companyId, (int) $appraisal->company_id);
        $this->assertSame(2, $appraisal->responses()->count());
    }

    public function test_a_failing_answer_is_marked_against_its_threshold(): void
    {
        $supplier = $this->makeSupplier();

        $question = $this->question($this->template(), 'Safety record', weight: 100, threshold: 4);

        $appraisal = app(SupplierAppraisalService::class)->store(
            $supplier->id, null, null, $this->user->id,
            [['question_library_id' => $question->id, 'rating' => 2]],
        );

        $response = $appraisal->responses()->first();

        $this->assertEqualsWithDelta(40.0, (float) $appraisal->total_score, 0.01);
        $this->assertFalse((bool) $response->passed);
    }

    /**
     * A poor appraisal has no consequence anywhere in the module.
     *
     * The score is stored and charted, but no threshold suspends the supplier, blocks a new order, or
     * excludes it from the next tender. Performance management stops at the report.
     */
    public function test_a_bottom_scoring_supplier_is_flagged_for_review(): void
    {
        $supplier = $this->makeSupplier(['is_active' => 1]);

        $question = $this->question($this->template(), 'Overall performance', weight: 100, threshold: 4);

        app(SupplierAppraisalService::class)->store(
            $supplier->id, null, null, $this->user->id,
            [['question_library_id' => $question->id, 'rating' => 1]],
        );

        $this->assertEqualsWithDelta(20.0, (float) SupplierAppraisal::latest('id')->first()->total_score, 0.01);
        $this->assertSame(1, (int) $supplier->fresh()->is_active);

        $this->post(route('suppliers.purchase-orders.store'), [
            'supplier_id' => $supplier->id, 'title' => 'More work', 'amount' => 5_000,
        ])->assertRedirect();

        // The order is still allowed — one bad quarter is a conversation, not a divorce — but it is
        // now a decision somebody makes against a visible flag rather than one that happens by default.
        $this->assertSame(
            1,
            \App\Modules\SupplierManagement\Models\PurchaseOrder::where('supplier_id', $supplier->id)->count(),
        );

        $flag = $supplier->riskFlags()->where('type', 'poor_appraisal')->where('status', 'open')->first();

        $this->assertNotNull($flag, 'A bottom-scoring appraisal must raise a risk flag.');
        $this->assertSame('medium', $flag->severity);
    }

    /** Repeated poor scores re-stamp the one live flag rather than stacking a year of duplicates. */
    public function test_successive_poor_appraisals_keep_a_single_open_flag(): void
    {
        $supplier = $this->makeSupplier(['is_active' => 1]);
        $question = $this->question($this->template(), 'Overall performance', weight: 100, threshold: 4);

        foreach ([1, 2] as $rating) {
            app(SupplierAppraisalService::class)->store(
                $supplier->id, null, null, $this->user->id,
                [['question_library_id' => $question->id, 'rating' => $rating]],
            );
        }

        $this->assertSame(1, $supplier->riskFlags()->where('type', 'poor_appraisal')->count());
    }

    /** A good score raises nothing. */
    public function test_a_strong_appraisal_raises_no_flag(): void
    {
        $supplier = $this->makeSupplier(['is_active' => 1]);
        $question = $this->question($this->template(), 'Overall performance', weight: 100, threshold: 4);

        app(SupplierAppraisalService::class)->store(
            $supplier->id, null, null, $this->user->id,
            [['question_library_id' => $question->id, 'rating' => 5]],
        );

        $this->assertSame(0, $supplier->riskFlags()->where('type', 'poor_appraisal')->count());
    }

    public function test_the_scorecard_screen_renders_for_a_supplier(): void
    {
        $supplier = $this->makeSupplier();

        $this->get(route('suppliers.scorecard', $supplier))->assertSuccessful();
    }

    // ── Read surfaces ────────────────────────────────────────────────────────

    public function test_every_read_only_screen_in_the_module_renders(): void
    {
        $supplier = $this->makeSupplier();

        foreach ([
            'suppliers.index', 'suppliers.create', 'suppliers.trash',
            'suppliers.onboarding.index', 'suppliers.onboarding.create',
            'suppliers.kyc.index', 'suppliers.sourcing.index', 'suppliers.sourcing.create',
            'suppliers.purchase-orders.index', 'suppliers.purchase-orders.create',
            'suppliers.invoices.index', 'suppliers.appraisals.index', 'suppliers.scars.index',
            'suppliers.settings.index', 'suppliers.reports', 'suppliers.analytics',
            'suppliers.search', 'suppliers.search.advanced', 'suppliers.spend.report',
            'suppliers.scheduled-reports.index',
        ] as $name) {
            $this->get(route($name))->assertSuccessful("Screen [$name] did not render.");
        }

        $this->get(route('suppliers.show', $supplier))->assertSuccessful();
        $this->get(route('suppliers.appraisals.appraise', $supplier))->assertSuccessful();
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** A supplier belonging to a named company, created without relying on ambient context. */
    private function makeSupplierFor(int $companyId): \App\Modules\SupplierManagement\Models\Supplier
    {
        $current = app(\App\Modules\Tenancy\Services\CurrentCompany::class);
        $was = $current->id();

        $current->set($companyId);

        try {
            return $this->makeSupplier(['is_active' => 0]);
        } finally {
            $current->set($was);
        }
    }

    /** A performance template. `supplier_question_libraries.template_id` is NOT NULL, so questions need one. */
    private function template(): SupplierPerformanceTemplate
    {
        return SupplierPerformanceTemplate::create(['name' => 'Standard appraisal '.uniqid(), 'status' => 1]);
    }

    private function question(SupplierPerformanceTemplate $template, string $text, float $weight, int $threshold): SupplierQuestionLibrary
    {
        return SupplierQuestionLibrary::create([
            'template_id'        => $template->id,
            'question'           => $text,
            'weight_percentage'  => $weight,
            'minimum_threshold'  => $threshold,
        ]);
    }

    public function test_the_dashboard_statistics_query_runs_within_the_tenant(): void
    {
        $this->makeSupplier(['is_active' => 1]);
        $this->makeSupplier(['is_active' => 0]);

        $stats = app(SupplierService::class)->dashboardStats();

        $this->assertIsArray($stats);
        $this->assertSame(2, Supplier::count());
    }
}
