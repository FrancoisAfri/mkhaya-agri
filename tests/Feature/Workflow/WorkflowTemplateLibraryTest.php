<?php

namespace Tests\Feature\Workflow;

use App\Modules\Workflow\Services\DefinitionService;
use App\Modules\Workflow\Services\FormService;
use App\Modules\Workflow\Services\GraphValidator;
use App\Modules\Workflow\Support\TemplateLibrary;
use App\Modules\Workflow\Support\WorkflowSettings;

/** Every shipped template installs into a company as a draft that passes validation and publishes. */
class WorkflowTemplateLibraryTest extends WorkflowTestCase
{
    public function test_every_template_installs_validates_and_publishes(): void
    {
        $this->settings(['publication_requires_review' => false]);
        $this->actingAs($this->admin);
        $definitions = app(DefinitionService::class);
        $templates = TemplateLibrary::everything();
        $this->assertGreaterThanOrEqual(31, count($templates));

        foreach ($templates as $key => $template) {
            $this->assertSame($key, $template['key'], "{$key}: key mismatch");
            foreach ($template['forms'] ?? [] as $form) {
                $this->assertSame([], app(FormService::class)->schemaProblems($form['fields']), "{$key}: form {$form['name']}");
            }

            $d = $definitions->installTemplate($key);
            $this->assertSame($this->company->id, $d->company_id, "{$key}: wrong company");
            $this->assertSame((bool) ($template['confidential'] ?? false), (bool) $d->is_confidential, "{$key}: confidentiality");
            $graph = $definitions->workingVersion($d)->graph;
            $this->assertSame([], app(GraphValidator::class)->validate($graph, $d), "{$key}: graph problems");

            $definitions->submit($d, $this->admin, 'Go live');
            $this->assertSame('published', $d->refresh()->status, "{$key}: not published");
        }

        $categories = app(WorkflowSettings::class)->categories($this->company->id);
        foreach ($templates as $template) {
            $this->assertContains($template['category'], $categories);
        }
    }

    public function test_a_platform_administrator_without_a_company_is_told_to_switch_in(): void
    {
        $this->admin->forceFill(['company_id' => null])->saveQuietly();
        app(\App\Modules\Tenancy\Services\CurrentCompany::class)->clear();
        $this->actingAs($this->admin)->from('/workflow/templates')
            ->post(route('workflow.templates.install', 'leave_request'))
            ->assertRedirect('/workflow/templates')->assertSessionHas('error');
    }
}
