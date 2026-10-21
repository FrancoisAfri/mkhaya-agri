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

    public function test_the_farm_processes_are_in_the_gallery_and_installing_one_brings_what_it_starts(): void
    {
        $this->settings(['publication_requires_review' => false]);
        $page = $this->actingAs($this->admin)->get(route('workflow.templates.index'))->assertOk()->viewData('page')['props']['templates'];
        $farm = collect($page)->where('category', 'Farm')->keyBy('key');
        $this->assertSame(['agri_crop_season', 'agri_field_season', 'agri_input_purchase', 'agri_insurance_claim'], $farm->keys()->sort()->values()->all());
        $this->assertSame(['Farm input purchase', 'Crop insurance claim', 'Field season: soil test to harvest'], collect($farm['agri_crop_season']['requires'])->all());

        $this->actingAs($this->admin)->post(route('workflow.templates.install', 'agri_crop_season'))->assertSessionHas('success', fn ($m) => str_contains($m, 'publish them first'));
        $installed = \App\Modules\Workflow\Models\WorkflowDefinition::query()->pluck('status', 'key');
        $this->assertSame(['agri_crop_season' => 'draft', 'agri_field_season' => 'draft', 'agri_input_purchase' => 'draft', 'agri_insurance_claim' => 'draft'], $installed->sortKeys()->all());

        // Published children first, then the season: every one passes the validator.
        $defs = app(DefinitionService::class);
        foreach (['agri_input_purchase', 'agri_insurance_claim', 'agri_field_season', 'agri_crop_season'] as $key) {
            $d = \App\Modules\Workflow\Models\WorkflowDefinition::query()->where('key', $key)->firstOrFail();
            $defs->submit($d, $this->admin, 'Go live');
            $this->assertSame('published', $d->refresh()->status, $key);
        }
        $this->assertNotEmpty(\App\Modules\Workflow\Models\WorkflowDefinition::query()->where('key', 'agri_field_season')->first()->currentVersion->graph['events'], 'the hail interruption is kept');
    }

    public function test_installing_the_farm_processes_waits_for_each_review_children_first(): void
    {
        $this->settings(['publication_requires_review' => true]);
        $this->actingAs($this->admin);
        $rounds = 0;
        do {
            $status = \App\Modules\Agri\Processes\CropSeasonProcess::install($this->admin);
            $waiting = collect($status)->filter(fn ($s) => $s !== 'published');
            if ($waiting->isNotEmpty()) {
                $this->assertSame(['in_review'], $waiting->values()->all(), 'one at a time: nothing is submitted before what it starts is published');
                $d = \App\Modules\Workflow\Models\WorkflowDefinition::query()->where('key', $waiting->keys()->first())->firstOrFail();
                app(DefinitionService::class)->review($d, $this->publisher, true, 'Checked');
            }
        } while ($waiting->isNotEmpty() && ++$rounds < 6);

        $this->assertSame(4, $rounds, 'four reviews, children first');
        $this->assertSame(array_fill_keys(\App\Modules\Agri\Processes\CropSeasonProcess::KEYS, 'published'), $status);
    }
}
