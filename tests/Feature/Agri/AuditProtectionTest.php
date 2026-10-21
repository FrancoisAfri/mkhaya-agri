<?php

namespace Tests\Feature\Agri;

use App\Modules\Agri\Models\FarmBudgetLine;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2 §15.2: sensitive changes leave an audit record of who did what, when and what changed;
 * and the database itself refuses to alter or erase an audit record.
 */
class AuditProtectionTest extends AgriTestCase
{
    public function test_sensitive_changes_are_audited_and_the_audit_cannot_be_rewritten(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $this->actingAs($this->manager)->post(route('agri.finance.budgets.store'), ['farm_id' => $farm->uuid, 'category' => 'repairs', 'amount' => 1000,
            'period_start' => today()->startOfMonth()->toDateString(), 'period_end' => today()->endOfMonth()->toDateString()])->assertSessionHasNoErrors();
        $line = FarmBudgetLine::withoutGlobalScopes()->sole();
        $this->actingAs($this->manager)->post(route('agri.finance.budgets.submit', $line->uuid))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('agri.finance.budgets.decide', $line->uuid), ['approve' => true])->assertSessionHasNoErrors();

        // Who approved, when, and what changed.
        $entry = DB::table('activity_log')->where('subject_type', FarmBudgetLine::class)->where('subject_id', $line->id)->where('causer_id', $this->admin->id)->latest('id')->first();
        $this->assertNotNull($entry, 'The approval is in the audit trail.');
        $this->assertStringContainsString('approved', (string) $entry->properties);
        $this->assertNotNull($entry->created_at);

        // Not even the application's own connection may change or remove it.
        try {
            DB::transaction(fn () => DB::table('activity_log')->where('id', $entry->id)->update(['description' => 'nothing to see']));
            $this->fail('An audit record was changed.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('cannot be changed', $e->getMessage());
        }
        try {
            DB::transaction(fn () => DB::table('activity_log')->where('id', $entry->id)->delete());
            $this->fail('An audit record was deleted.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('cannot be deleted', $e->getMessage());
        }
        $this->assertNotNull(DB::table('activity_log')->find($entry->id));

        // The retention job, and only it, declares a purge.
        DB::transaction(function () use ($entry) {
            DB::statement("SET LOCAL app.audit_purge = 'on'");
            DB::table('activity_log')->where('id', $entry->id)->delete();
        });
        $this->assertNull(DB::table('activity_log')->find($entry->id));
    }
}
