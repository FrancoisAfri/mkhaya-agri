<?php

namespace Tests\Feature\SupplierManagement;

use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierOnboarding;
use App\Modules\SupplierManagement\Notifications\SupplierOnboardingInvitation;
use App\Modules\SupplierManagement\Notifications\SupplierRegistrationSubmitted;
use App\Modules\SupplierManagement\Services\SupplierOnboardingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * The front door: a company is invited, registers itself, and becomes a supplier record.
 *
 * This is the step everything downstream trusts. If an invitation can be replayed after it expires,
 * or the same company can be onboarded twice under two registration numbers, then every payment,
 * award and appraisal further along is attached to a record nobody actually vetted.
 */
class SupplierOnboardingTest extends SupplierTestCase
{
    public function test_an_onboarding_invitation_is_created_with_a_token_and_an_expiry(): void
    {
        $onboarding = app(SupplierOnboardingService::class)->createOnboarding([
            'company_name'         => 'Prospect Ltd',
            'contact_person_email' => 'hello@prospect.test',
        ]);

        $this->assertSame('pending', $onboarding->status);
        $this->assertNotNull($onboarding->token);
        $this->assertSame(48, strlen($onboarding->token), 'The invite token is the credential; it must be long enough to resist guessing.');
        $this->assertTrue($onboarding->expires_at->isFuture());
        $this->assertSame($this->companyId, (int) $onboarding->company_id, 'An onboarding must be stamped to the tenant that raised it.');
    }

    public function test_the_invitation_endpoint_creates_a_record(): void
    {
        $this->post(route('suppliers.onboarding.store'), [
            'company_name'         => 'Prospect Two',
            'contact_person'       => 'Thandi M',
            'contact_person_email' => 'thandi@prospect2.test',
        ])->assertRedirect();

        $this->assertDatabaseHas('supplier_onboardings', [
            'company_name' => 'Prospect Two',
            'company_id'   => $this->companyId,
        ]);
    }

    public function test_a_duplicate_supplier_is_detected_by_registration_number_or_email(): void
    {
        $existing = $this->makeSupplier([
            'company_registration_number' => '2019/123456/07',
            'email'                       => 'dup@example.test',
        ]);

        $service = app(SupplierOnboardingService::class);

        $this->assertSame($existing->id, $service->checkExistingSupplier('2019/123456/07', null)?->id);
        $this->assertSame($existing->id, $service->checkExistingSupplier(null, 'dup@example.test')?->id);
        $this->assertNull($service->checkExistingSupplier('0000/000000/00', 'nobody@example.test'));
    }

    /**
     * A registration number this tenant already has is refused, and so is a repeated email.
     *
     * The duplicate check used to be advisory: it existed, worked, and no code path creating a
     * supplier was obliged to call it. Two records for one company means two sets of banking
     * details — which is what makes this a fraud surface rather than untidiness — so there is now a
     * unique index behind the check as well as the check itself.
     */
    public function test_a_duplicate_registration_number_or_email_is_refused(): void
    {
        $this->makeSupplier([
            'company_registration_number' => '2020/999999/07',
            'email'                       => 'twin@example.test',
        ]);

        $this->post(route('suppliers.store'), [
            'company_name'                => 'Twin Co',
            'company_registration_number' => '2020/999999/07',
        ])->assertSessionHasErrors('company_registration_number');

        $this->post(route('suppliers.store'), [
            'company_name' => 'Twin Co By Email',
            'email'        => 'twin@example.test',
        ])->assertSessionHasErrors('email');

        $this->assertSame(1, Supplier::where('company_registration_number', '2020/999999/07')->count());
    }

    /** A supplier with no registration number is not a duplicate of every other blank one. */
    public function test_suppliers_without_a_registration_number_do_not_collide(): void
    {
        foreach (['First Blank', 'Second Blank'] as $name) {
            $this->post(route('suppliers.store'), ['company_name' => $name])->assertRedirect();
        }

        $this->assertSame(2, Supplier::whereIn('company_name', ['First Blank', 'Second Blank'])->count());
    }

    /**
     * An expired invitation stops being a usable credential.
     *
     * `expires_at` was written at creation, refreshed on resend, and never read back — so the
     * 24-hour window it described was decorative and every invitation link worked forever.
     *
     * Asserted through the registration page rather than a service predicate, because the page is
     * where the rule is enforced and the only place it can be got wrong. It also distinguishes the
     * two refusals, which a boolean cannot: an expired link sends somebody back to their contact for
     * a fresh one, a wrong link sends them hunting for a typo, and telling them apart is the
     * difference between a supplier who registers and one who gives up.
     */
    public function test_an_expired_onboarding_token_is_rejected(): void
    {
        $service = app(SupplierOnboardingService::class);

        $onboarding = $service->createOnboarding(['company_name' => 'Stale Co']);

        $this->get(route('supplier.registration.show', $onboarding->token))->assertOk();
        $this->assertFalse($service->hasExpired($onboarding));

        $onboarding->forceFill(['expires_at' => Carbon::now()->subYear()])->save();

        $this->get(route('supplier.registration.show', $onboarding->fresh()->token))->assertStatus(410);
        $this->assertTrue($service->hasExpired($onboarding->fresh()));
    }

    /**
     * Resending mints a new token, so the old link stops working.
     *
     * That is what "resend" has to mean when the link is the credential — otherwise every resend
     * widens the set of live URLs for one supplier.
     */
    public function test_resending_an_invitation_invalidates_the_previous_link(): void
    {
        Notification::fake();

        $service = app(SupplierOnboardingService::class);
        $onboarding = $service->createOnboarding([
            'company_name' => 'Resend Co', 'contact_person_email' => 'resend@example.test',
        ]);
        $original = $onboarding->token;

        $this->post(route('suppliers.onboarding.send-mail', $onboarding->id))->assertRedirect();

        $onboarding->refresh();

        $this->assertNotSame($original, $onboarding->token);

        // Asserted through the registration page, which is where the token is actually spent. The
        // old link must stop working the moment a new one is issued, or a forwarded email stays a
        // live credential for as long as somebody keeps it.
        $this->get(route('supplier.registration.show', $original))->assertNotFound();
        $this->get(route('supplier.registration.show', $onboarding->token))->assertOk();
    }

    /** The invitation is actually sent — it used to be generated and left in the database. */
    public function test_creating_an_invitation_emails_the_contact(): void
    {
        Notification::fake();

        $this->post(route('suppliers.onboarding.store'), [
            'company_name'         => 'Mailed Co',
            'contact_person_email' => 'buyer-contact@example.test',
        ])->assertRedirect();

        Notification::assertSentOnDemand(
            SupplierOnboardingInvitation::class,
            fn ($notification, $channels, $notifiable) =>
                $notifiable->routes['mail'] === 'buyer-contact@example.test',
        );
    }

    // ── Registration: the door the invitation is a key to ────────────────────

    /**
     * An invited company can register itself, unauthenticated, and becomes a pending supplier.
     *
     * Before this existed the token was a credential for a lock with no door: the only public surface
     * was the portal, which needs a supplier record the invited company does not have yet. The
     * invitation was sent, and there was nowhere to take it.
     */
    public function test_an_invited_company_can_register_itself_from_the_token(): void
    {
        $onboarding = app(SupplierOnboardingService::class)->createOnboarding([
            'company_name'         => 'Prospect Engineering',
            'contact_person'       => 'Naledi Mokoena',
            'contact_person_email' => 'naledi@prospect-eng.test',
        ]);

        $this->becomeStranger();

        $this->get(route('supplier.registration.show', $onboarding->token))->assertSuccessful();

        $this->post(route('supplier.registration.store', $onboarding->token), [
            'company_name'                => 'Prospect Engineering (Pty) Ltd',
            'company_registration_number' => '2024/554433/07',
            'email'                       => 'accounts@prospect-eng.test',
            'phone_number'                => '+27 11 555 9000',
            'bank_name'                   => 'Standard Bank',
            'bank_account_number'         => '1234567890',
            'contacts'                    => [['first_name' => 'Naledi', 'surname' => 'Mokoena', 'email' => 'naledi@prospect-eng.test', 'tel' => '']],
            'directors'                   => [['first_name' => 'Thabo', 'surname' => 'Dlamini', 'email' => '', 'tel' => '']],
        ])->assertRedirect();

        $supplier = Supplier::withoutGlobalScopes()->where('company_name', 'Prospect Engineering (Pty) Ltd')->first();

        $this->assertNotNull($supplier, 'Registering should create the supplier.');
        $this->assertSame($this->companyId, (int) $supplier->company_id, 'The supplier must land in the tenant that invited them.');
        $this->assertSame(0, (int) $supplier->is_active, 'A self-described company is pending, not active.');
        $this->assertSame(1, $supplier->contacts()->count());
        $this->assertSame(1, $supplier->directors()->count());

        $onboarding->refresh();
        $this->assertSame($supplier->id, $onboarding->supplier_id);
        $this->assertSame('submitted', $onboarding->status);
    }

    /**
     * A self-registered supplier is owned by whoever invited them, and that person is told.
     *
     * Ownership is not bookkeeping: `procurement:expiry-alerts` returns early on a null user id, so a
     * supplier created with no owner would never raise a compliance alert to anybody — the lapse
     * would simply never be mentioned.
     */
    public function test_registration_assigns_an_owner_and_notifies_them(): void
    {
        Notification::fake();

        $onboarding = app(SupplierOnboardingService::class)->createOnboarding([
            'company_name' => 'Owned Co',
            'employee_id'  => $this->user->id,
        ]);

        $this->becomeStranger();

        $this->post(route('supplier.registration.store', $onboarding->token), [
            'company_name' => 'Owned Co Ltd', 'email' => 'owned@example.test',
        ])->assertRedirect();

        $supplier = Supplier::withoutGlobalScopes()->where('company_name', 'Owned Co Ltd')->firstOrFail();

        $this->assertSame($this->user->id, (int) $supplier->created_by, 'An ownerless supplier gets no compliance alerts.');

        Notification::assertSentTo(
            $this->user,
            SupplierRegistrationSubmitted::class,
            fn ($notification) => $notification->supplierId === $supplier->id,
        );
    }

    /** The invitation is spent once used — one key, one company. */
    public function test_a_registration_token_cannot_be_used_twice(): void
    {
        $onboarding = app(SupplierOnboardingService::class)->createOnboarding(['company_name' => 'Once Only']);

        $this->becomeStranger();

        $this->post(route('supplier.registration.store', $onboarding->token), [
            'company_name' => 'Once Only Ltd', 'email' => 'once@example.test',
        ])->assertRedirect();

        $this->post(route('supplier.registration.store', $onboarding->token), [
            'company_name' => 'Second Bite Ltd', 'email' => 'second@example.test',
        ])->assertStatus(410);

        $this->assertSame(0, Supplier::withoutGlobalScopes()->where('company_name', 'Second Bite Ltd')->count());
    }

    /** An expired invitation is refused, and says so rather than 404ing. */
    public function test_registration_is_refused_once_the_invitation_has_lapsed(): void
    {
        $onboarding = app(SupplierOnboardingService::class)->createOnboarding(['company_name' => 'Too Late']);
        $onboarding->forceFill(['expires_at' => Carbon::now()->subDay()])->save();

        $this->becomeStranger();

        $this->get(route('supplier.registration.show', $onboarding->token))->assertStatus(410);
        $this->post(route('supplier.registration.store', $onboarding->token), [
            'company_name' => 'Too Late Ltd', 'email' => 'late@example.test',
        ])->assertStatus(410);
    }

    /** A company already on the list is told so rather than given a second record. */
    public function test_registering_a_company_already_on_the_list_is_refused(): void
    {
        $this->makeSupplier(['company_registration_number' => '2019/111111/07']);

        $onboarding = app(SupplierOnboardingService::class)->createOnboarding(['company_name' => 'Already Here']);

        $this->becomeStranger();

        $this->post(route('supplier.registration.store', $onboarding->token), [
            'company_name'                => 'Already Here Ltd',
            'company_registration_number' => '2019/111111/07',
            'email'                       => 'dup@already.test',
        ])->assertSessionHasErrors('company_registration_number');

        $this->assertNull($onboarding->fresh()->supplier_id);
    }

    /** Drop the authenticated session so the flow runs as an invited stranger would see it. */
    private function becomeStranger(): void
    {
        auth()->logout();
        app(\App\Modules\Tenancy\Services\CurrentCompany::class)->set(null);
    }

    public function test_onboarding_edit_and_delete_round_trip(): void
    {
        $onboarding = app(SupplierOnboardingService::class)->createOnboarding(['company_name' => 'Editable Co']);

        $this->patch(route('suppliers.onboarding.update', $onboarding->id), [
            'company_name' => 'Renamed Co',
            'status'       => 'in_progress',
        ])->assertRedirect();

        $this->assertSame('Renamed Co', $onboarding->fresh()->company_name);

        $this->delete(route('suppliers.onboarding.destroy', $onboarding->id))->assertRedirect();
        $this->assertSoftDeleted('supplier_onboardings', ['id' => $onboarding->id]);
    }

}
