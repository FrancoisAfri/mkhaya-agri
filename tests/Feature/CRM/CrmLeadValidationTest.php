<?php

namespace Tests\Feature\CRM;

use App\Core\User\Models\User;
use App\Modules\CRM\Models\CrmLead;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A refused save has to say so.
 *
 * "I click Save, the page loads, then nothing" — reported against a lead whose email had been
 * typed without the @. The server was right to refuse it and did: 422, errors on the response,
 * no row written. The dialog was the problem. Not one of its fields was bound to `form.errors`,
 * so the reason arrived in the browser and had nowhere to be shown; `onSuccess` never fired, the
 * dialog stayed open, and the interface looked identical to a save that had silently failed.
 *
 * These tests hold the server half of that contract — the refusal, the reason, and the absence of
 * a half-written row — so a future change to the rules cannot quietly stop producing the message
 * the dialog now depends on.
 */
class CrmLeadValidationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (! DB::table('security_modules')->where('code_name', 'crm')->where('active', 1)->exists()) {
            $categoryId = DB::table('security_categories')->value('id')
                ?? DB::table('security_categories')->insertGetId(['name' => 'Test', 'created_at' => now(), 'updated_at' => now()]);

            DB::table('security_modules')->updateOrInsert(
                ['code_name' => 'crm'],
                ['uuid' => (string) Str::uuid(), 'name' => 'CRM', 'path' => 'crm', 'category_id' => $categoryId,
                    'active' => 1, 'created_at' => now(), 'updated_at' => now()],
            );
        }

        Cache::flush();
    }

    private function user(): User
    {
        return User::forceCreate([
            'uuid'              => (string) Str::uuid(),
            'email'             => 'leadval+' . Str::random(8) . '@example.test',
            'password'          => bcrypt('secret'),
            'status'            => 1,
            'is_platform_admin' => true,
        ]);
    }

    public function test_an_address_with_no_at_sign_is_refused_with_a_reason_on_the_email_field(): void
    {
        // Exactly what was typed: a domain, which reads like an address until you look for the @.
        $response = $this->actingAs($this->user())->post(route('crm.leads.store'), [
            'first_name' => 'Paul',
            'last_name'  => 'Manson',
            'company'    => 'The Collaborative Exchange',
            'email'      => 'thecollaborative.co.za',
            'phone'      => '+27828172997',
            'source'     => 'Word of Mouth',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertDatabaseMissing('crm_leads', ['company' => 'The Collaborative Exchange']);
    }

    public function test_the_same_lead_saves_once_the_address_is_a_real_one(): void
    {
        $response = $this->actingAs($this->user())->post(route('crm.leads.store'), [
            'first_name' => 'Paul',
            'last_name'  => 'Manson',
            'company'    => 'The Collaborative Exchange',
            'email'      => 'paul@thecollaborative.co.za',
            'phone'      => '+27828172997',
            'source'     => 'Word of Mouth',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('crm_leads', [
            'company' => 'The Collaborative Exchange',
            'email'   => 'paul@thecollaborative.co.za',
            'status'  => 'new',
        ]);
    }

    public function test_a_lead_needs_a_first_name_and_says_which_field_is_missing(): void
    {
        $response = $this->actingAs($this->user())->post(route('crm.leads.store'), [
            'first_name' => '',
            'company'    => 'No Name Co',
        ]);

        $response->assertSessionHasErrors('first_name');
        $this->assertDatabaseMissing('crm_leads', ['company' => 'No Name Co']);
    }

    public function test_an_email_is_optional_so_a_phone_only_lead_still_saves(): void
    {
        // The rule is nullable|email, not required — a lead taken over the phone has no address,
        // and refusing it would be a worse bug than the one being fixed.
        $response = $this->actingAs($this->user())->post(route('crm.leads.store'), [
            'first_name' => 'Phone',
            'last_name'  => 'Only',
            'company'    => 'Walk In Trading',
            'phone'      => '+27110000000',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('crm_leads', ['company' => 'Walk In Trading']);
    }

    public function test_a_refused_save_writes_nothing_at_all(): void
    {
        $before = CrmLead::query()->count();

        $this->actingAs($this->user())->post(route('crm.leads.store'), [
            'first_name' => 'Partial',
            'email'      => 'not-an-address',
        ]);

        $this->assertSame($before, CrmLead::query()->count(), 'A refused save must not leave a half-written lead.');
    }
}
