<?php

namespace Tests\Feature\Workflow;

use App\Modules\Workflow\Models\WorkflowForm;
use App\Modules\Workflow\Services\FormService;
use App\Modules\Workflow\Support\FormLibrary;

/** Every shipped form is valid and installs as the company's own editable copy. */
class WorkflowFormLibraryTest extends WorkflowTestCase
{
    public function test_every_library_form_is_valid(): void
    {
        $all = FormLibrary::everything();
        $this->assertGreaterThanOrEqual(40, count(FormLibrary::standalone()));
        foreach ($all as $key => $form) {
            $this->assertMatchesRegularExpression('/^[a-z0-9_]{1,120}$/', $key);
            $this->assertSame([], app(FormService::class)->schemaProblems($form['fields']), "{$key} has schema problems");
        }
    }

    public function test_adding_a_form_from_the_library_gives_the_company_its_own_copy(): void
    {
        $this->actingAs($this->admin)->get(route('workflow.forms.library'))->assertOk();
        $this->actingAs($this->admin)->post(route('workflow.forms.library.install', 'bank_details_change'))->assertRedirect();

        $form = WorkflowForm::query()->where('name', 'Employee bank details change')->firstOrFail();
        $this->assertSame($this->company->id, $form->company_id);
        $this->assertSame(1, $form->currentVersion->version);
        $this->assertSame(array_column(FormLibrary::get('bank_details_change')['fields'], 'key'), array_column($form->currentVersion->fields, 'key'));

        // A template's form can be taken on its own too.
        $this->actingAs($this->admin)->post(route('workflow.forms.library.install', 'it_change__change'))->assertRedirect();
        $this->assertTrue(WorkflowForm::query()->where('name', 'Change request')->exists());
    }

    public function test_a_hidden_required_field_is_not_demanded(): void
    {
        $fields = FormLibrary::get('refund_request')['fields'];
        [, $errors] = app(FormService::class)->validate($fields, ['customer' => 'ACME', 'amount' => '100', 'method' => 'Original card', 'reason' => 'Returned']);
        $this->assertSame([], $errors);
    }

    public function test_the_library_needs_design_rights_and_rejects_unknown_forms(): void
    {
        $this->actingAs($this->requester)->get(route('workflow.forms.library'))->assertForbidden();
        $this->actingAs($this->requester)->post(route('workflow.forms.library.install', 'general_request'))->assertForbidden();
        $this->actingAs($this->admin)->post(route('workflow.forms.library.install', 'no_such_form'))->assertNotFound();
    }
}
