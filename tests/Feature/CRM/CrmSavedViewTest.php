<?php

namespace Tests\Feature\CRM;

use App\Core\User\Models\User;
use App\Modules\CRM\Models\CrmLead;
use App\Modules\CRM\Models\CrmSavedView;
use App\Modules\CRM\Services\CrmSegmentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class CrmSavedViewTest extends TestCase
{
    use DatabaseTransactions;

    private function users(): array
    {
        $users = User::query()->take(2)->get();
        if ($users->count() < 2) $this->markTestSkipped('Need two users.');
        return [$users[0], $users[1]];
    }

    public function test_segment_service_applies_whitelisted_filter(): void
    {
        CrmLead::create(['uuid' => Str::uuid(), 'first_name' => 'A', 'status' => 'new']);
        CrmLead::create(['uuid' => Str::uuid(), 'first_name' => 'B', 'status' => 'qualified']);

        $svc = app(CrmSegmentService::class);
        $q = CrmLead::query();
        $svc->apply($q, 'lead', [['field' => 'status', 'operator' => '=', 'value' => 'qualified']]);

        $this->assertSame(1, $q->count());
    }

    public function test_segment_service_ignores_non_whitelisted_field(): void
    {
        CrmLead::create(['uuid' => Str::uuid(), 'first_name' => 'A', 'status' => 'new']);

        $svc = app(CrmSegmentService::class);
        $q = CrmLead::query();
        $svc->apply($q, 'lead', [['field' => 'first_name', 'operator' => '=', 'value' => 'nope']]); // not whitelisted for lead

        // Filter ignored -> still counts the lead.
        $this->assertSame(1, $q->count());
    }

    public function test_visible_to_scope_returns_own_and_shared(): void
    {
        [$me, $other] = $this->users();

        $mine   = CrmSavedView::create(['uuid' => Str::uuid(), 'user_id' => $me->id, 'entity' => 'lead', 'name' => 'Mine', 'is_shared' => false]);
        $shared = CrmSavedView::create(['uuid' => Str::uuid(), 'user_id' => $other->id, 'entity' => 'lead', 'name' => 'Team', 'is_shared' => true]);
        $hidden = CrmSavedView::create(['uuid' => Str::uuid(), 'user_id' => $other->id, 'entity' => 'lead', 'name' => 'Theirs', 'is_shared' => false]);

        $visible = CrmSavedView::visibleTo($me->id, 'lead')->pluck('id');

        $this->assertContains($mine->id, $visible);
        $this->assertContains($shared->id, $visible);
        $this->assertNotContains($hidden->id, $visible);
    }

    public function test_cannot_delete_someone_elses_view(): void
    {
        [$me, $other] = $this->users();
        $view = CrmSavedView::create(['uuid' => Str::uuid(), 'user_id' => $other->id, 'entity' => 'lead', 'name' => 'Theirs', 'is_shared' => true]);

        $this->actingAs($me)
            ->delete(route('crm.saved-views.destroy', $view->id))
            ->assertForbidden();

        $this->assertDatabaseHas('crm_saved_views', ['id' => $view->id]);
    }
}
