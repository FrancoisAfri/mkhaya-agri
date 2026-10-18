<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The lifecycle each register could describe and not perform.
 *
 * Three registers could create a record and nothing else, while the services behind them carried
 * the whole lifecycle:
 *
 * - A legal appointment under OHSA 16(2) could not be made, accepted or revoked. A module built
 *   for South African health and safety law could not record the appointments that law turns on.
 * - A controlled document could be published and never acknowledged or withdrawn, so the only
 *   evidence that a procedure reached the people it governs could not be captured (SHEQ-DOC-2).
 * - PPE could be issued and never returned, so issued stock read as permanently consumed.
 */
class RegisterLifecycleTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private User $user;

    private int $hrPersonId;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;

        $userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'reg-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'company_id' => $this->companyId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->user = User::withoutGlobalScopes()->findOrFail($userId);

        $this->hrPersonId = DB::table('hr_people')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId, 'user_id' => $userId,
            'first_name' => 'Thabo', 'surname' => 'Mokoena', 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $moduleId = DB::table('security_modules')->where('code_name', 'sheq')->value('id');

        if ($moduleId === null) {
            $this->markTestSkipped('SHEQ module is not registered.');
        }

        DB::table('security_module_access')->insert([
            'module_id' => $moduleId, 'user_id' => $userId, 'access_level' => 4,
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('security_modules')->where('id', $moduleId)->update(['active' => 1]);
        Cache::forget('module_active_sheq');

        foreach (['sheq.competency.manage', 'sheq.document.manage', 'sheq.configure'] as $permission) {
            $this->user->givePermissionTo($permission);
        }

        $this->user->forgetCachedPermissions();
    }

    private function appointmentCompetency(): string
    {
        $code = 'APP'.strtoupper(Str::random(5));

        DB::table('sheq_competencies')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => $code, 'title' => 'Section 16(2) appointee',
            'competency_type' => 'APPOINTMENT', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $code;
    }

    public function test_a_legal_appointment_can_be_made_accepted_and_revoked(): void
    {
        $code = $this->appointmentCompetency();

        $this->actingAs($this->user)->post('/sheq/competency/appoint', [
            'hr_person_id' => $this->hrPersonId,
            'competency_code' => $code,
            'scope' => 'Responsible for the workshop and the yard.',
            'evidence_path' => 'appointments/16-2-letter.pdf',
            'effective_from' => Carbon::today()->toDateString(),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $record = DB::table('sheq_competency_records')
            ->where('company_id', $this->companyId)->where('hr_person_id', $this->hrPersonId)
            ->latest('id')->first(['id', 'accepted_at', 'revoked_at']);

        $this->assertNotNull($record, 'OHSA 16(2) appointments could not be recorded at all.');
        $this->assertNull($record->accepted_at, 'An appointment takes effect once it is signed.');

        // Nobody is appointed without agreeing to be.
        $this->actingAs($this->user)
            ->post("/sheq/competency/records/{$record->id}/accept", ['signature' => 'T Mokoena'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNotNull(DB::table('sheq_competency_records')
            ->where('id', $record->id)->value('accepted_at'));

        // An appointment that outlives the job is a liability.
        $this->actingAs($this->user)
            ->post("/sheq/competency/records/{$record->id}/revoke", ['reason' => 'Moved to another site'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNotNull(DB::table('sheq_competency_records')
            ->where('id', $record->id)->value('revoked_at'));
    }

    public function test_an_appointment_without_its_signed_letter_is_refused(): void
    {
        $code = $this->appointmentCompetency();

        // SHEQ-COMP-8: the letter is the appointment. Validated here so the person is told which
        // field to fill rather than handed the service's refusal as a sentence.
        $this->actingAs($this->user)->post('/sheq/competency/appoint', [
            'hr_person_id' => $this->hrPersonId,
            'competency_code' => $code,
            'scope' => 'The workshop.',
        ])->assertSessionHasErrors();
    }

    public function test_an_appointment_without_a_scope_is_refused(): void
    {
        $code = $this->appointmentCompetency();

        // An appointment with no scope names somebody responsible for nothing in particular.
        $this->actingAs($this->user)->post('/sheq/competency/appoint', [
            'hr_person_id' => $this->hrPersonId,
            'competency_code' => $code,
            'evidence_path' => 'appointments/letter.pdf',
        ])->assertSessionHasErrors('scope');
    }

    public function test_appointing_requires_the_entitlement(): void
    {
        $code = $this->appointmentCompetency();

        $outsiderId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'reg-no-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'company_id' => $this->companyId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('security_module_access')->insert([
            'module_id' => DB::table('security_modules')->where('code_name', 'sheq')->value('id'),
            'user_id' => $outsiderId, 'access_level' => 4, 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs(User::withoutGlobalScopes()->findOrFail($outsiderId))
            ->post('/sheq/competency/appoint', [
                'hr_person_id' => $this->hrPersonId, 'competency_code' => $code,
                'scope' => 'x', 'evidence_path' => 'y',
            ])->assertForbidden();
    }
}
