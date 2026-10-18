<?php

namespace Tests\Feature\CRM;

use App\Modules\CRM\Models\CrmCustomField;
use App\Modules\CRM\Models\CrmLead;
use App\Modules\CRM\Services\CrmCustomFieldService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class CrmCustomFieldTest extends TestCase
{
    use DatabaseTransactions;

    private function field(string $entity, string $name, string $type = 'text', array $options = []): CrmCustomField
    {
        return CrmCustomField::create([
            'entity_type' => $entity, 'name' => $name, 'label' => ucfirst($name),
            'field_type' => $type, 'options' => $options ?: null, 'is_required' => false,
            'active' => true, 'sort_order' => 1,
        ]);
    }

    public function test_save_and_read_values_with_casting(): void
    {
        $this->field('lead', 'budget', 'number');
        $this->field('lead', 'industry', 'select', ['Mining', 'Retail']);
        $this->field('lead', 'vip', 'boolean');

        $lead = CrmLead::create(['uuid' => Str::uuid(), 'first_name' => 'CF', 'status' => 'new']);

        $svc = app(CrmCustomFieldService::class);
        $svc->saveValues('lead', $lead->id, ['budget' => '50000', 'industry' => 'Mining', 'vip' => true]);

        $values = $svc->values('lead', $lead->id);
        $this->assertSame(50000, $values['budget']);   // cast to number
        $this->assertSame('Mining', $values['industry']);
        $this->assertTrue($values['vip']);              // cast to boolean
    }

    public function test_unknown_field_names_are_ignored(): void
    {
        $this->field('lead', 'budget', 'number');
        $lead = CrmLead::create(['uuid' => Str::uuid(), 'first_name' => 'X', 'status' => 'new']);

        app(CrmCustomFieldService::class)->saveValues('lead', $lead->id, ['budget' => '10', 'nonexistent' => 'x']);

        $values = app(CrmCustomFieldService::class)->values('lead', $lead->id);
        $this->assertArrayHasKey('budget', $values);
        $this->assertArrayNotHasKey('nonexistent', $values);
        $this->assertDatabaseMissing('crm_custom_field_values', ['entity_id' => $lead->id, 'value' => 'x']);
    }

    public function test_values_scoped_per_entity_type(): void
    {
        $this->field('lead', 'shared', 'text');
        $this->field('account', 'shared', 'text');
        $lead = CrmLead::create(['uuid' => Str::uuid(), 'first_name' => 'S', 'status' => 'new']);

        $svc = app(CrmCustomFieldService::class);
        $svc->saveValues('lead', $lead->id, ['shared' => 'lead-value']);

        // Same entity_id under a different entity type must not collide.
        $this->assertSame('lead-value', $svc->values('lead', $lead->id)['shared']);
        $this->assertArrayNotHasKey('shared', $svc->values('account', $lead->id));
    }

    public function test_inactive_fields_excluded_from_definitions(): void
    {
        $active = $this->field('deal', 'active_f', 'text');
        $inactive = $this->field('deal', 'inactive_f', 'text');
        $inactive->update(['active' => false]);

        $names = collect(app(CrmCustomFieldService::class)->definitionsForUi('deal'))->pluck('name');
        $this->assertContains('active_f', $names);
        $this->assertNotContains('inactive_f', $names);
    }
}
