<?php

namespace Tests\Feature\CRM;

use App\Core\User\Models\User;
use App\Modules\CRM\Models\CrmLead;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class CrmAuditTest extends TestCase
{
    use DatabaseTransactions;

    private function user(): User
    {
        $user = User::query()->first();
        if (! $user) $this->markTestSkipped('No users available.');
        return $user;
    }

    public function test_create_and_update_are_logged_with_diff(): void
    {
        Auth::login($this->user());

        $lead = CrmLead::create(['uuid' => Str::uuid(), 'first_name' => 'Original', 'status' => 'new']);
        $lead->update(['first_name' => 'Changed']);

        $created = Activity::where('log_name', 'CRM')->where('subject_id', $lead->id)
            ->where('subject_type', 'like', '%CrmLead')->where('event', 'created')->first();
        $updated = Activity::where('log_name', 'CRM')->where('subject_id', $lead->id)
            ->where('subject_type', 'like', '%CrmLead')->where('event', 'updated')->latest()->first();

        $this->assertNotNull($created, 'creation should be logged');
        $this->assertNotNull($updated, 'update should be logged');

        // The diff captures the old and new value.
        $this->assertSame('Original', $updated->properties['old']['first_name'] ?? null);
        $this->assertSame('Changed', $updated->properties['attributes']['first_name'] ?? null);
    }

    public function test_audit_page_lists_crm_activity(): void
    {
        $user = $this->user();
        Auth::login($user);
        $lead = CrmLead::create(['uuid' => Str::uuid(), 'first_name' => 'Audited', 'status' => 'new']);

        $this->actingAs($user)
            ->get(route('crm.audit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Modules/CRM/AuditTrail')
                ->has('activities'));
    }
}
